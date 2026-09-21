<?php

namespace App\Filament\Resources\B2bLeads\Pages;

use App\Filament\Pages\SchedulingConfiguration;
use App\Filament\Resources\B2bLeads\B2bLeadResource;
use App\Filament\Support\LocalizedListRecords;
use App\Modules\B2B\Application\GetB2bSalesCallReadiness;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Icons\Heroicon;

final class ListB2bLeads extends LocalizedListRecords
{
    protected static string $resource = B2bLeadResource::class;

    protected static ?string $title = 'B2B-лиды';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('b2bConfiguration')
                ->label(__('Настроить слоты и Zoom'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->url(SchedulingConfiguration::getUrl())
                ->visible(SchedulingConfiguration::canAccess()),
            CreateAction::make()->label(__('Новый B2B-лид')),
        ];
    }

    public function getSubheading(): string
    {
        $readiness = app(GetB2bSalesCallReadiness::class)->handle();
        $duration = $readiness['durationConfigured'] ? __('Настроено') : __('Требуется действие');
        $calendar = $readiness['calendarConfigured'] ? __('Настроено') : __('Требуется действие');
        $zoom = $readiness['automaticZoomConfigured'] ? __('Настроено') : __('Не настроено');
        $manual = $readiness['manualLinkFallbackAvailable'] ? __('Доступна') : __('Не настроено');

        return __('Длительность разговора: :duration · календарь специалиста: :calendar · автоматическая встреча Zoom: :zoom · ручная ссылка: :manual. Время и исключения настраиваются в разделах «Настройки расписания», «Изменения расписания» и «Недоступное время».', [
            'duration' => $duration,
            'calendar' => $calendar,
            'zoom' => $zoom,
            'manual' => $manual,
        ]);
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
