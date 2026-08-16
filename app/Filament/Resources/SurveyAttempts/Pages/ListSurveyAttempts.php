<?php

namespace App\Filament\Resources\SurveyAttempts\Pages;

use App\Filament\Resources\SurveyAttempts\SurveyAttemptResource;
use Filament\Resources\Pages\ListRecords;

final class ListSurveyAttempts extends ListRecords
{
    protected static string $resource = SurveyAttemptResource::class;
}
