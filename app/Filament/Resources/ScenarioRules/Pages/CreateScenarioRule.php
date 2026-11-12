<?php

namespace App\Filament\Resources\ScenarioRules\Pages;

use App\Filament\Resources\ScenarioRules\ScenarioRuleResource;
use App\Filament\Support\InteractsWithFormValidationFeedback;
use App\Filament\Support\LocalizedCreateRecord;
use App\Models\User;
use App\Modules\Scenarios\Application\CreateScenarioRule as CreateScenarioRuleAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CreateScenarioRule extends LocalizedCreateRecord
{
    use InteractsWithFormValidationFeedback;

    protected static string $resource = ScenarioRuleResource::class;

    protected static ?string $title = 'Создать авто-сообщение';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            return app(CreateScenarioRuleAction::class)->handle($actor, $data);
        } catch (ValidationException $exception) {
            throw $this->formValidationFailure($exception, $data);
        } catch (Throwable $exception) {
            throw $this->unexpectedFormFailure($exception, $data);
        }
    }
}
