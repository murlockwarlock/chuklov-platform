<?php

namespace App\Filament\Resources\Specialists\Tables;

use App\Models\User;
use App\Modules\Specialists\Application\SetSpecialistActive;
use App\Modules\Specialists\Application\UpdateSpecialist;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SpecialistsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')->label('Full name')->searchable()->sortable(),
                IconColumn::make('is_active')->boolean()->sortable(),
                TextColumn::make('timezone')->placeholder('Organization fallback'),
                TextColumn::make('staffUser.name')->label('Linked staff User')->placeholder('Not linked'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('activate')
                    ->label('Activate')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Specialist $record): bool => ! $record->is_active)
                    ->action(function (Specialist $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(SetSpecialistActive::class)->handle($actor, $record, true);
                    }),
                Action::make('deactivate')
                    ->label('Deactivate')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Checkbox::make('acknowledge_impact')
                            ->label('Acknowledge impact on future bookings')
                            ->default(false),
                    ])
                    ->visible(fn (Specialist $record): bool => $record->is_active)
                    ->action(function (Specialist $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(SetSpecialistActive::class)->handle(
                            $actor,
                            $record,
                            false,
                            (bool) ($data['acknowledge_impact'] ?? false),
                        );
                    }),
                Action::make('linkStaffUser')
                    ->label('Link staff User')
                    ->schema([
                        Select::make('staff_user_id')
                            ->required()
                            ->searchable()
                            ->options(fn (): array => SpecialistTableOptions::staffUsers()),
                    ])
                    ->visible(fn (Specialist $record): bool => $record->staff_user_id === null)
                    ->action(function (Specialist $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(UpdateSpecialist::class)->handle(
                            actor: $actor,
                            specialist: $record,
                            displayName: $record->display_name,
                            isActive: $record->is_active,
                            timezone: $record->timezone,
                            staffUserId: (int) $data['staff_user_id'],
                        );
                    }),
                Action::make('unlinkStaffUser')
                    ->label('Unlink staff User')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Specialist $record): bool => $record->staff_user_id !== null)
                    ->action(function (Specialist $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(UpdateSpecialist::class)->handle(
                            actor: $actor,
                            specialist: $record,
                            displayName: $record->display_name,
                            isActive: $record->is_active,
                            timezone: $record->timezone,
                            staffUserId: null,
                        );
                    }),
            ]);
    }
}
