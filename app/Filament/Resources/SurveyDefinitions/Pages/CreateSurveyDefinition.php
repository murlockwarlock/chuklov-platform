<?php

namespace App\Filament\Resources\SurveyDefinitions\Pages;

use App\Filament\Resources\SurveyDefinitions\SurveyDefinitionResource;
use App\Models\User;
use App\Modules\Surveys\Application\CreateSurveyDefinition as CreateSurveyDefinitionAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateSurveyDefinition extends CreateRecord
{
    protected static string $resource = SurveyDefinitionResource::class;

    protected static ?string $title = 'Создать тест';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateSurveyDefinitionAction::class)->handle($actor, EditSurveyDefinition::normalize($data));
    }
}
