<?php

namespace App\Filament\Resources\SurveyAttempts\Pages;

use App\Filament\Resources\SurveyAttempts\SurveyAttemptResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewSurveyAttempt extends ViewRecord
{
    protected static string $resource = SurveyAttemptResource::class;

    protected static ?string $title = 'Результат теста';
}
