<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Tables;

use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Filament\Resources\Specialists\SpecialistResource;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\CrmLabel;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class SessionsTable
{
    public static function configure(Table $table): Table
    {
        $canViewSessions = MedicalSessionResource::canViewAny();
        $canManageSessions = MedicalSessionResource::canCreate();
        $canViewSpecialists = SpecialistResource::canViewAny();

        return $table
            ->paginated([25, 50])
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('Дата сеанса'))
                    ->dateTime('d.m.Y H:i')
                    ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone()),
                TextColumn::make('specialist.display_name')
                    ->label(__('Специалист'))
                    ->placeholder('—')
                    ->wrap()
                    ->url(fn (MedicalSession $record): ?string => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists))
                    ->color(fn (MedicalSession $record): ?string => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists) === null ? null : 'primary')
                    ->disabledClick(fn (MedicalSession $record): bool => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists) === null),
                TextColumn::make('booking_starts_at')
                    ->label(__('Дата записи на приём'))
                    ->state(fn ($record): ?string => $record->booking === null
                        ? null
                        : Carbon::parse((string) $record->booking->getAttribute('starts_at'), 'UTC')
                            ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
                            ->format('d.m.Y H:i'))
                    ->placeholder(__('Не связан')),
                TextColumn::make('booking_status')
                    ->label(__('Статус записи'))
                    ->state(fn ($record): ?string => $record->booking === null ? null : self::statusLabel($record->booking->status))
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('Открыть'))
                    ->visible($canViewSessions)
                    ->url(fn ($record): string => MedicalSessionResource::getUrl('view', ['record' => $record], shouldGuessMissingParameters: true)),
                Action::make('edit')
                    ->label(__('Редактировать'))
                    ->visible($canManageSessions)
                    ->url(fn ($record): string => MedicalSessionResource::getUrl('edit', ['record' => $record], shouldGuessMissingParameters: true)),
            ])
            ->toolbarActions([
                Action::make('create')
                    ->label(__('Новый сеанс'))
                    ->icon('heroicon-o-plus')
                    ->url(fn (): string => MedicalSessionResource::getUrl('create', shouldGuessMissingParameters: true))
                    ->visible($canManageSessions),
            ]);
    }

    private static function statusLabel(BookingStatus $status): string
    {
        return CrmLabel::enum($status) ?? __('Без статуса');
    }
}
