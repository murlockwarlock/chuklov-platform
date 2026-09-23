<?php

namespace App\Filament\Resources\AiEvaluations\Pages;

use App\Filament\Resources\AiEvaluations\AiEvaluationResource;
use App\Filament\Support\LocalizedEditRecord;
use App\Models\User;
use App\Modules\AI\Application\Actions\UpdateAiEvaluationSuite;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use Illuminate\Database\Eloquent\Model;

class EditAiEvaluation extends LocalizedEditRecord
{
    protected static string $resource = AiEvaluationResource::class;

    protected static ?string $title = 'Редактировать проверку AI';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof AiEvalSuite, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateAiEvaluationSuite::class)->handle($actor, $record, $data);
    }
}
