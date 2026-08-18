<?php

namespace App\Filament\Resources\KnowledgeSources\Pages;

use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use App\Models\User;
use App\Modules\Knowledge\Application\CreateKnowledgeSource as CreateKnowledgeSourceAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateKnowledgeSource extends CreateRecord
{
    protected static string $resource = KnowledgeSourceResource::class;

    protected static ?string $title = 'Добавить источник знаний';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateKnowledgeSourceAction::class)->handle($actor, $data);
    }
}
