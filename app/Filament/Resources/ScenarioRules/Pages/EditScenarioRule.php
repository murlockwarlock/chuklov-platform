<?php

namespace App\Filament\Resources\ScenarioRules\Pages;

use App\Filament\Resources\ScenarioRules\ScenarioRuleResource;
use App\Filament\Support\InteractsWithFormValidationFeedback;
use App\Filament\Support\LocalizedEditRecord;
use App\Models\User;
use App\Modules\Scenarios\Application\UpdateScenarioRule;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EditScenarioRule extends LocalizedEditRecord
{
    use InteractsWithFormValidationFeedback;

    protected static string $resource = ScenarioRuleResource::class;

    protected static ?string $title = 'Редактировать авто-сообщение';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof ScenarioRule, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            return app(UpdateScenarioRule::class)->handle($actor, $record, [
                ...$data,
                'rule_key' => $record->rule_key,
            ]);
        } catch (ValidationException $exception) {
            throw $this->formValidationFailure($exception, $data);
        } catch (Throwable $exception) {
            throw $this->unexpectedFormFailure($exception, $data);
        }
    }
}
