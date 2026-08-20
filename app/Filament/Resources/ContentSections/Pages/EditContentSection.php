<?php

namespace App\Filament\Resources\ContentSections\Pages;

use App\Filament\Resources\ContentSections\ContentSectionResource;
use App\Models\User;
use App\Modules\Content\Application\UpdateContentSection;
use App\Modules\Content\Domain\Contracts\ContentMediaStorageInterface;
use App\Modules\Content\Domain\Models\ContentSection;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditContentSection extends EditRecord
{
    protected static string $resource = ContentSectionResource::class;

    protected static ?string $title = 'Редактировать раздел контента';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        abort_unless($this->record instanceof ContentSection, 404);

        $image = $data['media']['image'] ?? null;

        if (is_string($image)
            && app(ContentMediaStorageInterface::class)->isManagedPath((int) $this->record->organization_id, $image)
        ) {
            unset($data['media']['image']);
        }

        $data['remove_image'] = false;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof ContentSection, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateContentSection::class)->handle($actor, $record, $data);
    }
}
