<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Models\User;
use App\Modules\Identity\Application\BlockClientSelfBooking;
use App\Modules\Identity\Application\UnblockClientSelfBooking;
use App\Modules\Identity\Domain\Models\Client;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')->label('Full name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->placeholder('—'),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('language')->sortable(),
                TextColumn::make('timezone')->sortable(),
                TextColumn::make('channel_identities_count')->label('Channels')->sortable(),
                IconColumn::make('activeBookingRestriction')
                    ->label('Self-booking blocked')
                    ->boolean()
                    ->state(fn (Client $record): bool => $record->activeBookingRestriction !== null),
            ])
            ->filters([
                TernaryFilter::make('activeBookingRestriction')
                    ->label('Self-booking blocked')
                    ->queries(
                        true: fn ($query) => $query->whereHas('activeBookingRestriction'),
                        false: fn ($query) => $query->whereDoesntHave('activeBookingRestriction'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('blockSelfBooking')
                    ->label('Block self-booking')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->visible(fn (Client $record): bool => $record->activeBookingRestriction === null)
                    ->action(function (Client $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(BlockClientSelfBooking::class)->handle($actor, $record, $data['reason']);
                    }),
                Action::make('unblockSelfBooking')
                    ->label('Allow self-booking')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Client $record): bool => $record->activeBookingRestriction !== null)
                    ->action(function (Client $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(UnblockClientSelfBooking::class)->handle($actor, $record);
                    }),
            ]);
    }
}
