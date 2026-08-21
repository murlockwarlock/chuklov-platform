<?php

namespace App\Filament\Resources\AiEvaluations\Pages;

use App\Filament\Resources\AiEvaluations\AiEvaluationResource;
use App\Models\User;
use App\Modules\AI\Application\Actions\UpdateAiEvaluationSuite;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAiEvaluation extends EditRecord
{
    protected static string $resource = AiEvaluationResource::class;

    protected static ?string $title = 'Редактировать набор тестов AI';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof AiEvalSuite, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateAiEvaluationSuite::class)->handle($actor, $record, $data);
    }
}
