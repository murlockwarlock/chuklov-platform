<?php

namespace App\Filament\Resources\UnavailablePeriods\Tables;

use App\Models\User;
use App\Modules\Scheduling\Application\DeleteUnavailablePeriod;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UnavailablePeriodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('specialist.display_name')->label('Specialist')->sortable(),
                TextColumn::make('starts_at')->label('Starts at')->dateTime()->sortable(),
                TextColumn::make('ends_at')->label('Ends at')->dateTime()->sortable(),
                TextColumn::make('reason')->limit(80)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label('Delete')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (UnavailablePeriod $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(DeleteUnavailablePeriod::class)->handle($actor, $record);
                    }),
            ]);
    }
}
