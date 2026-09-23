<?php

namespace App\Filament\Resources\SurveyDefinitions\Pages;

use App\Filament\Resources\SurveyDefinitions\SurveyDefinitionResource;
use App\Filament\Support\LocalizedCreateRecord;
use App\Filament\Support\SurveyDefinitionFormMapper;
use App\Models\User;
use App\Modules\Surveys\Application\CreateSurveyDefinition as CreateSurveyDefinitionAction;
use Illuminate\Database\Eloquent\Model;

final class CreateSurveyDefinition extends LocalizedCreateRecord
{
    protected static string $resource = SurveyDefinitionResource::class;

    protected static ?string $title = 'Создать тест';

    public string $surveyBuilderTab = '0';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateSurveyDefinitionAction::class)->handle($actor, SurveyDefinitionFormMapper::normalize($data));
    }
}
