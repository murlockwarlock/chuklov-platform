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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class CreateContentSection
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
        private readonly ContentMediaStorageInterface $media,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User $actor, array $attributes): ContentSection
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageContent);
        $uploadedFile = $this->uploadedFile($attributes);
        $this->assertMediaInput($attributes, $uploadedFile);
        unset($attributes['content_image'], $attributes['remove_image']);
        $storedPath = null;

        try {
            if ($uploadedFile !== null) {
                $storedPath = $this->media->store($organization->getKey(), $uploadedFile);
                $attributes['media'] = $this->mediaWithImage($attributes['media'] ?? null, $storedPath);
            }

            $data = ContentSectionData::from($attributes);

            return DB::transaction(function () use ($actor, $data, $organization): ContentSection {
                $section = new ContentSection;
                $section->forceFill([
                    'organization_id' => $organization->getKey(),
                    ...$data->attributes(),
                ]);
                $section->save();

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'content.section.created',
                    targetType: ContentSection::class,
                    targetId: (string) $section->getKey(),
                    metadata: [
                        'section_key' => $data->sectionKey,
                        'locale' => $data->locale,
                        'is_visible' => $data->isVisible,
                    ],
                );

                return $section->refresh();
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
    private function assertMediaInput(array $attributes, ?UploadedFile $uploadedFile): void
    {
        $media = $attributes['media'] ?? null;
        $hasImage = is_array($media)
            && is_string($media['image'] ?? null)
            && trim($media['image']) !== '';

        if ($uploadedFile !== null && $hasImage) {
            throw ValidationException::withMessages([
                'content_image' => ['Выберите файл или ссылку на изображение.'],
                'media.image' => ['Выберите файл или ссылку на изображение.'],
            ]);
        }

        if ($hasImage) {
            try {
                ContentExternalImageUrl::required($media['image']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'media.image' => ['Укажите корректную HTTPS-ссылку на изображение.'],
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function mediaWithImage(mixed $value, string $path): array
    {
        if ($value === null) {
            return ['image' => $path];
        }

        if (! is_array($value)) {
            throw ValidationException::withMessages([
                'content_image' => ['Данные изображения заполнены некорректно.'],
            ]);
        }

        $value['image'] = $path;

        return $value;
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
