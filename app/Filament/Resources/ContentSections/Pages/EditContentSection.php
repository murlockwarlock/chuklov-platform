<?php

namespace App\Filament\Resources\ContentSections\Pages;

use App\Filament\Resources\ContentSections\ContentSectionResource;
use App\Filament\Support\LocalizedEditRecord;
use App\Models\User;
use App\Modules\Content\Application\AttachContentSubtitles;
use App\Modules\Content\Application\AttachContentVideo;
use App\Modules\Content\Application\UpdateContentSection;
use App\Modules\Content\Domain\Contracts\ContentMediaStorageInterface;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Content\Domain\ValueObjects\ContentExternalImageUrl;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class EditContentSection extends LocalizedEditRecord
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('uploadVideo')
                ->label(__('Загрузить видео'))
                ->visible(fn (): bool => $this->record instanceof ContentSection && static::getResource()::canEdit($this->record))
                ->schema([
                    FileUpload::make('content_video')->label(__('Видео'))
                        ->acceptedFileTypes(['video/mp4'])->maxSize(10240)->storeFiles(false)->required(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User && $this->record instanceof ContentSection, 403);
                    app(AttachContentVideo::class)->handle($actor, $this->record, $data['content_video']);
                    $this->record->refresh();
                    Notification::make()->title(__('Видео сохранено'))->success()->send();
                }),
            Action::make('uploadSubtitles')
                ->label(__('Загрузить субтитры'))
                ->visible(fn (): bool => $this->record instanceof ContentSection && static::getResource()::canEdit($this->record))
                ->schema([
                    FileUpload::make('content_subtitles')->label(__('Субтитры WebVTT'))
                        ->acceptedFileTypes(['text/vtt', 'text/plain'])->maxSize(1024)->storeFiles(false)->required(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User && $this->record instanceof ContentSection, 403);
                    app(AttachContentSubtitles::class)->handle($actor, $this->record, $data['content_subtitles']);
                    $this->record->refresh();
                    $this->data['media']['subtitles'] = null;
                    Notification::make()->title(__('Субтитры сохранены'))->success()->send();
                }),
        ];
    }

    protected static string $resource = ContentSectionResource::class;

    protected static ?string $title = 'Редактировать раздел контента';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        abort_unless($this->record instanceof ContentSection, 404);

        $image = $data['media']['image'] ?? null;

        $hideImage = is_string($image)
            && app(ContentMediaStorageInterface::class)->isManagedPath((int) $this->record->organization_id, $image);

        if (! $hideImage && $image !== null && $image !== '') {
            try {
                ContentExternalImageUrl::required($image);
            } catch (InvalidArgumentException) {
                $hideImage = true;
            }
        }

        if ($hideImage) {
            unset($data['media']['image']);
        }

        $data['remove_image'] = false;

        $subtitles = $data['media']['subtitles'] ?? null;
        if (is_string($subtitles) && app(ContentMediaStorageInterface::class)->isManagedPath((int) $this->record->organization_id, $subtitles)) {
            unset($data['media']['subtitles']);
        }

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
