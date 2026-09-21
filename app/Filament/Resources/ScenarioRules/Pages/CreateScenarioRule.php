<?php

namespace App\Filament\Resources\ScenarioRules\Pages;

use App\Filament\Resources\ScenarioRules\ScenarioRuleResource;
use App\Filament\Support\LocalizedCreateRecord;
use App\Models\User;
use App\Modules\Scenarios\Application\CreateScenarioRule as CreateScenarioRuleAction;
use Illuminate\Database\Eloquent\Model;

final class CreateScenarioRule extends LocalizedCreateRecord
{
    protected static string $resource = ScenarioRuleResource::class;

    protected static ?string $title = 'Создать авто-сообщение';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateScenarioRuleAction::class)->handle($actor, $data);
    }
}
