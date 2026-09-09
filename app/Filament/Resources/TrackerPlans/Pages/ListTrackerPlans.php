<?php

namespace App\Filament\Resources\TrackerPlans\Pages;

use App\Filament\Resources\TrackerPlans\TrackerPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListTrackerPlans extends ListRecords
{
    protected static string $resource = TrackerPlanResource::class;

    protected static ?string $title = 'Трекер и тарифы';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Добавить тариф')];
    }
}
