<?php

namespace App\Filament\Resources\TrackerPlans\Pages;

use App\Filament\Resources\TrackerPlans\TrackerPlanResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

final class ListTrackerPlans extends LocalizedListRecords
{
    protected static string $resource = TrackerPlanResource::class;

    protected static ?string $title = 'Трекер и тарифы';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Добавить тариф'))];
    }
}
