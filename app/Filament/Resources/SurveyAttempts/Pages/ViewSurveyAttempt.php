<?php

namespace App\Filament\Resources\SurveyAttempts\Pages;

use App\Filament\Resources\SurveyAttempts\SurveyAttemptResource;
use App\Filament\Support\LocalizedViewRecord;

final class ViewSurveyAttempt extends LocalizedViewRecord
{
    protected static string $resource = SurveyAttemptResource::class;

    protected static ?string $title = 'Результат теста';
}
