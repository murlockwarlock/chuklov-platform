<?php

namespace App\Filament\Resources\UnavailablePeriods\Tables;

use App\Filament\Resources\Specialists\SpecialistResource;
use App\Filament\Support\CrmEntityLinks;
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
        $canViewSpecialists = SpecialistResource::canViewAny();

        return $table
            ->columns([
                TextColumn::make('specialist.display_name')
                    ->label(__('Специалист'))
                    ->sortable()
                    ->url(fn (UnavailablePeriod $record): ?string => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists))
                    ->color(fn (UnavailablePeriod $record): ?string => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists) === null ? null : 'primary')
                    ->disabledClick(fn (UnavailablePeriod $record): bool => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists) === null),
                TextColumn::make('starts_at')
                    ->label(__('Начало'))
                    ->dateTime('d.m.Y H:i')
                    ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label(__('Окончание'))
                    ->dateTime('d.m.Y H:i')
                    ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                    ->sortable(),
                TextColumn::make('reason')->label(__('Причина'))->limit(80)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label(__('Удалить'))
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
