<?php

namespace App\Filament\Resources\ContentSections\Pages;

use App\Filament\Resources\ContentSections\ContentSectionResource;
use App\Models\User;
use App\Modules\Content\Application\CreateContentSection as CreateContentSectionAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateContentSection extends CreateRecord
{
    protected static string $resource = ContentSectionResource::class;

    protected static ?string $title = 'Создать раздел контента';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateContentSectionAction::class)->handle($actor, $data);
    }
}
