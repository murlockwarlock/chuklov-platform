<?php

namespace App\Filament\Resources\SurveyDefinitions\Pages;

use App\Filament\Resources\SurveyDefinitions\SurveyDefinitionResource;
use App\Filament\Support\InteractsWithFormValidationFeedback;
use App\Filament\Support\LocalizedCreateRecord;
use App\Filament\Support\SurveyDefinitionFormMapper;
use App\Models\User;
use App\Modules\Surveys\Application\CreateSurveyDefinition as CreateSurveyDefinitionAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CreateSurveyDefinition extends LocalizedCreateRecord
{
    use InteractsWithFormValidationFeedback;

    protected static string $resource = SurveyDefinitionResource::class;

    protected static ?string $title = 'Создать тест';

    public string $surveyBuilderTab = '0';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            return app(CreateSurveyDefinitionAction::class)->handle($actor, SurveyDefinitionFormMapper::normalize($data));
        } catch (ValidationException $exception) {
            throw $this->formValidationFailure($exception, $data);
        } catch (Throwable $exception) {
            throw $this->unexpectedFormFailure($exception, $data);
        }
    }
}
