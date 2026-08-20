<?php

namespace App\Modules\Content\Application;

use App\Models\User;
use App\Modules\Content\Domain\Contracts\ContentMediaStorageInterface;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Content\Domain\ValueObjects\ContentExternalImageUrl;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class UpdateContentSection
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
        private readonly ContentMediaStorageInterface $media,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User $actor, ContentSection $section, array $attributes): ContentSection
    {
        $organization = $this->context->organization();

        if ((int) $section->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The content section is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageContent);
        $uploadedFile = $this->uploadedFile($attributes);
        $removeImage = (bool) ($attributes['remove_image'] ?? false);
        $mediaMode = $this->mediaMode($attributes, $uploadedFile, $removeImage, $section, $organization->getKey());
        unset($attributes['content_image'], $attributes['remove_image']);
        $storedPath = null;

        try {
            if ($uploadedFile !== null) {
                $storedPath = $this->media->store($organization->getKey(), $uploadedFile);
            }

            return DB::transaction(function () use ($actor, $attributes, $mediaMode, $organization, $section, $storedPath): ContentSection {
                $lockedSection = ContentSection::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($section->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $finalAttributes = [
                    ...$attributes,
                    'media' => $this->mediaAttributes(
                        $lockedSection,
                        $attributes['media'] ?? null,
                        $mediaMode,
                        $storedPath,
                    ),
                ];
                $data = ContentSectionData::from($finalAttributes);
                $changedFields = [];

                foreach ($data->attributes() as $field => $value) {
                    if ($lockedSection->getAttribute($field) !== $value) {
                        $changedFields[] = $field;
                    }
                }

                $oldImagePath = $this->imagePath($lockedSection->media);
                $lockedSection->forceFill($data->attributes());
                $lockedSection->save();

                $newImagePath = $this->imagePath($data->media);

                if (is_string($oldImagePath)
                    && $oldImagePath !== $newImagePath
                    && $this->media->isManagedPath($organization->getKey(), $oldImagePath)
                ) {
                    DB::afterCommit(function () use ($organization, $oldImagePath): void {
                        try {
                            $this->media->deleteManaged($organization->getKey(), $oldImagePath);
                        } catch (Throwable $cleanupException) {
                            report($cleanupException);
                        }
                    });
                }

                if ($changedFields !== []) {
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: 'content.section.updated',
                        targetType: ContentSection::class,
                        targetId: (string) $lockedSection->getKey(),
                        metadata: [
                            'section_key' => $data->sectionKey,
                            'locale' => $data->locale,
                            'is_visible' => $data->isVisible,
                        ],
                    );
                }

                return $lockedSection->refresh();
            });
        } catch (Throwable $exception) {
            $this->discard($organization->getKey(), $storedPath);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    private function uploadedFile(array $attributes): ?UploadedFile
    {
        $value = $attributes['content_image'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! $value instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'content_image' => ['Загрузите изображение в формате JPG, PNG или WebP.'],
            ]);
        }

        return $value;
    }

    /** @param array<string, mixed> $attributes */
    private function mediaMode(
        array $attributes,
        ?UploadedFile $uploadedFile,
        bool $removeImage,
        ContentSection $section,
        int $organizationId,
    ): string {
        $media = $attributes['media'] ?? null;
        $hasImageKey = is_array($media) && array_key_exists('image', $media);
        $requestedImage = is_array($media) ? $media['image'] ?? null : null;
        $hasImage = is_string($requestedImage) && trim($requestedImage) !== '';
        $currentImage = $this->imagePath($section->media);
        $isCurrentImage = $hasImage && trim((string) $requestedImage) === $currentImage;

        if ($uploadedFile !== null && $hasImage && ! $isCurrentImage) {
            throw ValidationException::withMessages([
                'content_image' => ['Выберите файл или ссылку на изображение.'],
                'media.image' => ['Выберите файл или ссылку на изображение.'],
            ]);
        }

        if ($removeImage && $hasImage && ! $isCurrentImage) {
            throw ValidationException::withMessages([
                'content_image' => ['Выберите изображение или включите удаление текущего изображения.'],
                'media.image' => ['Выберите изображение или включите удаление текущего изображения.'],
            ]);
        }

        if ($uploadedFile !== null) {
            return 'upload';
        }

        if ($removeImage) {
            return 'remove';
        }

        if ($hasImage) {
            $requestedImage = trim((string) $requestedImage);
            $currentImage = $this->imagePath($section->media);

            if ($this->media->isManagedPath($organizationId, $requestedImage)) {
                if ($requestedImage === $currentImage) {
                    return 'preserve';
                }

                throw ValidationException::withMessages([
                    'media.image' => ['Выберите изображение или ссылку на изображение.'],
                ]);
            }

            return 'external';
        }

        return $currentImage !== null || ! $hasImageKey ? 'preserve' : 'clear';
    }

    /** @return array<string, mixed>|null */
    private function mediaAttributes(
        ContentSection $section,
        mixed $requestedMedia,
        string $mediaMode,
        ?string $storedPath,
    ): ?array {
        if ($requestedMedia !== null && ! is_array($requestedMedia)) {
            throw ValidationException::withMessages([
                'media.image' => ['Данные изображения заполнены некорректно.'],
            ]);
        }

        $currentMedia = is_array($section->media) ? $section->media : [];
        $finalMedia = [...$currentMedia, ...($requestedMedia ?? [])];

        if ($mediaMode === 'upload') {
            if ($storedPath === null) {
                throw ValidationException::withMessages([
                    'content_image' => ['Не удалось сохранить изображение.'],
                ]);
            }

            $finalMedia['image'] = $storedPath;
        }

        if ($mediaMode === 'external') {
            try {
                $finalMedia['image'] = ContentExternalImageUrl::required($finalMedia['image'])->value;
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'media.image' => ['Укажите корректную HTTPS-ссылку на изображение.'],
                ]);
            }
        }

        if (in_array($mediaMode, ['clear', 'remove'], true)) {
            unset($finalMedia['image']);
        }

        if ($mediaMode === 'preserve') {
            $currentImage = $this->imagePath($section->media);

            if ($currentImage !== null) {
                $finalMedia['image'] = $currentImage;
            }
        }

        return $finalMedia === [] ? null : $finalMedia;
    }

    /** @param array<string, string>|null $media */
    private function imagePath(?array $media): ?string
    {
        $image = $media['image'] ?? null;

        return is_string($image) && trim($image) !== '' ? trim($image) : null;
    }

    private function discard(int $organizationId, ?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            $this->media->deleteManaged($organizationId, $path);
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }
}
