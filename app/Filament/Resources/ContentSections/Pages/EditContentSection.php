<?php

namespace App\Filament\Resources\ContentSections\Pages;

use App\Filament\Resources\ContentSections\ContentSectionResource;
use App\Filament\Support\FormValidationFeedback;
use App\Filament\Support\InteractsWithFormValidationFeedback;
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
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class EditContentSection extends LocalizedEditRecord
{
    use InteractsWithFormValidationFeedback;

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
                ->action(function (Action $action, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User && $this->record instanceof ContentSection, 403);
                    try {
                        app(AttachContentVideo::class)->handle($actor, $this->record, $data['content_video']);
                        $this->record->refresh();
                        Notification::make()->title(__('Видео сохранено'))->success()->send();
                    } catch (ValidationException $exception) {
                        $mapped = FormValidationFeedback::forAction($exception, $action);
                        FormValidationFeedback::notifyValidation($mapped);
                        throw $mapped;
                    } catch (Throwable $exception) {
                        $mapped = FormValidationFeedback::unexpectedForAction($exception, $action);
                        FormValidationFeedback::notifyValidation($mapped);
                        throw $mapped;
                    }
                }),
            Action::make('uploadSubtitles')
                ->label(__('Загрузить субтитры'))
                ->visible(fn (): bool => $this->record instanceof ContentSection && static::getResource()::canEdit($this->record))
                ->schema([
                    FileUpload::make('content_subtitles')->label(__('Субтитры WebVTT'))
                        ->acceptedFileTypes(['text/vtt', 'text/plain'])->maxSize(1024)->storeFiles(false)->required(),
                ])
                ->action(function (Action $action, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User && $this->record instanceof ContentSection, 403);
                    try {
                        app(AttachContentSubtitles::class)->handle($actor, $this->record, $data['content_subtitles']);
                        $this->record->refresh();
                        $this->data['media']['subtitles'] = null;
                        Notification::make()->title(__('Субтитры сохранены'))->success()->send();
                    } catch (ValidationException $exception) {
                        $mapped = FormValidationFeedback::forAction($exception, $action);
                        FormValidationFeedback::notifyValidation($mapped);
                        throw $mapped;
                    } catch (Throwable $exception) {
                        $mapped = FormValidationFeedback::unexpectedForAction($exception, $action);
                        FormValidationFeedback::notifyValidation($mapped);
                        throw $mapped;
                    }
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

        try {
            return app(UpdateContentSection::class)->handle($actor, $record, $data);
        } catch (ValidationException $exception) {
            throw $this->formValidationFailure($exception, $data);
        } catch (Throwable $exception) {
            throw $this->unexpectedFormFailure($exception, $data);
        }
    }
}
