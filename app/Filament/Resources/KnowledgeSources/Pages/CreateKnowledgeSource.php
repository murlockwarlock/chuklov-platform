<?php

namespace App\Filament\Resources\KnowledgeSources\Pages;

use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use App\Filament\Support\InteractsWithFormValidationFeedback;
use App\Filament\Support\LocalizedCreateRecord;
use App\Models\User;
use App\Modules\Knowledge\Application\CreateKnowledgeSource as CreateKnowledgeSourceAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CreateKnowledgeSource extends LocalizedCreateRecord
{
    use InteractsWithFormValidationFeedback;

    protected static string $resource = KnowledgeSourceResource::class;

    protected static ?string $title = 'Добавить источник знаний';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            return app(CreateKnowledgeSourceAction::class)->handle($actor, $data);
        } catch (ValidationException $exception) {
            throw $this->formValidationFailure($exception, $data);
        } catch (Throwable $exception) {
            throw $this->unexpectedFormFailure($exception, $data);
        }
    }
}
