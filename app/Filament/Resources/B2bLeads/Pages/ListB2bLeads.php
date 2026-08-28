<?php

namespace App\Filament\Resources\B2bLeads\Pages;

use App\Filament\Pages\SchedulingConfiguration;
use App\Filament\Resources\B2bLeads\B2bLeadResource;
use App\Modules\B2B\Application\GetB2bSalesCallReadiness;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListB2bLeads extends ListRecords
{
    protected static string $resource = B2bLeadResource::class;

    protected static ?string $title = 'B2B-лиды';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('b2bConfiguration')
                ->label('Настроить слоты и Zoom')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->url(SchedulingConfiguration::getUrl())
                ->visible(SchedulingConfiguration::canAccess()),
            CreateAction::make()->label('Новый B2B-лид'),
        ];
    }

    public function getSubheading(): string
    {
        $readiness = app(GetB2bSalesCallReadiness::class)->handle();
        $duration = $readiness['durationConfigured'] ? 'Настроено' : 'Требуется действие';
        $calendar = $readiness['calendarConfigured'] ? 'Настроено' : 'Требуется действие';
        $zoom = $readiness['automaticZoomConfigured'] ? 'Настроено' : 'Не настроено';
        $manual = $readiness['manualLinkFallbackAvailable'] ? 'Доступна' : 'Не настроено';

        return "Длительность: {$duration} · календарь специалиста: {$calendar} · автоматический Zoom: {$zoom} · ручная HTTPS-ссылка: {$manual}. Слоты берутся из «Настроек расписания»; исключения — в «Изменениях расписания», паузы — в «Недоступном времени».";
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
