<?php

namespace App\Filament\Resources\ScheduleExceptions;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

final class ScheduleChangeTypeActions
{
    public static function make(bool $unavailable): ActionGroup
    {
        return ActionGroup::make([
            Action::make('scheduleIntervals')->label(__('Рабочие интервалы на отдельные даты'))
                ->url(ScheduleExceptionResource::getUrl('create', ['change_type' => 'working_intervals']))->visible(fn (): bool => ScheduleExceptionResource::canCreate()),
            Action::make('unavailablePeriods')->label(__('Недоступное время'))
                ->url(ScheduleExceptionResource::getUrl('create', ['change_type' => 'unavailable']))->visible(fn (): bool => ScheduleExceptionResource::canCreate()),
        ])->label(__('Тип изменения').': '.($unavailable ? __('Недоступное время') : __('Рабочие интервалы')))
            ->button()->color('gray');
    }
}
