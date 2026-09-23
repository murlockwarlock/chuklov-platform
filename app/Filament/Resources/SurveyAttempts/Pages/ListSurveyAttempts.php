<?php

namespace App\Filament\Resources\SurveyAttempts\Pages;

use App\Filament\Resources\SurveyAttempts\SurveyAttemptResource;
use App\Filament\Support\LocalizedListRecords;

final class ListSurveyAttempts extends LocalizedListRecords
{
    protected static string $resource = SurveyAttemptResource::class;

    protected static ?string $title = 'Результаты тестов';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
