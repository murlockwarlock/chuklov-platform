<?php

namespace App\Filament\Resources\ScenarioRules\Pages;

use App\Filament\Resources\ScenarioRules\ScenarioRuleResource;
use App\Models\User;
use App\Modules\Scenarios\Application\UpdateScenarioRule;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditScenarioRule extends EditRecord
{
    protected static string $resource = ScenarioRuleResource::class;

    protected static ?string $title = 'Редактировать правило сообщений';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof ScenarioRule, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateScenarioRule::class)->handle($actor, $record, [
            ...$data,
            'rule_key' => $record->rule_key,
        ]);
    }
}
