<?php

namespace App\Filament\Resources\ScheduleExceptions;

use App\Filament\Resources\UnavailablePeriods\UnavailablePeriodResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

final class ScheduleChangeTypeActions
{
    public static function make(bool $unavailable): ActionGroup
    {
        return ActionGroup::make([
            Action::make('scheduleIntervals')->label(__('Рабочие интервалы на отдельные даты'))
                ->url(ScheduleExceptionResource::getUrl('index'))->visible(fn (): bool => ScheduleExceptionResource::canViewAny()),
            Action::make('unavailablePeriods')->label(__('Недоступное время'))
                ->url(UnavailablePeriodResource::getUrl('index'))->visible(fn (): bool => UnavailablePeriodResource::canViewAny()),
        ])->label(__('Тип изменения').': '.($unavailable ? __('Недоступное время') : __('Рабочие интервалы')))
            ->button()->color('gray');
    }
}
