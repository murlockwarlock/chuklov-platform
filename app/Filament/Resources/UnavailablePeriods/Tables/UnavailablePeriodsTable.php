<?php

namespace App\Filament\Resources\UnavailablePeriods\Tables;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
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
                TextColumn::make('specialist.display_name')->label('Специалист')->sortable(),
                TextColumn::make('starts_at')
                    ->label('Начало')
                    ->dateTime('d.m.Y H:i')
                    ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Окончание')
                    ->dateTime('d.m.Y H:i')
                    ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                    ->sortable(),
                TextColumn::make('reason')->label('Причина')->limit(80)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label('Удалить')
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
