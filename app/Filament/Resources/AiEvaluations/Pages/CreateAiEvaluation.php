<?php

namespace App\Filament\Resources\AiEvaluations\Pages;

use App\Filament\Resources\AiEvaluations\AiEvaluationResource;
use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAiEvaluationSuite;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAiEvaluation extends CreateRecord
{
    protected static string $resource = AiEvaluationResource::class;

    protected static ?string $title = 'Создать проверку AI';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateAiEvaluationSuite::class)->handle($actor, $data);
    }
}
