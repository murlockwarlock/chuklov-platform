<?php

namespace App\Modules\Services\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Services\Domain\Contracts\ServiceMediaStorageInterface;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Services\Domain\ValueObjects\ServiceConfiguration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateService
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly RecordAuditEvent $audit,
        private readonly ServiceMediaStorageInterface $media,
    ) {}

    /**
     * @param  string|array<string, mixed>  $name
     * @param  list<string>  $formats
     */
    public function handle(
        User $actor,
        string|array $name,
        string $summary = '',
        bool $isActive = true,
        ?string $nameRu = null,
        ?string $nameEn = null,
        ?string $descriptionRu = null,
        ?string $descriptionEn = null,
        ?string $category = null,
        ?int $durationMinutes = null,
        int $bufferMinutes = 0,
        array $formats = [],
        ?int $priceMinor = null,
        ?string $priceCurrency = null,
        ?string $paymentPolicy = null,
        string $catalogType = 'service',
    ): Service {
        $organization = $this->context->organization();
        $this->features->authorize($organization, OrganizationFeature::ServiceCatalog);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageServices);

        $attributes = is_array($name) ? $name : [
            'name' => $name,
            'summary' => $summary,
            'is_active' => $isActive,
            'catalog_type' => $catalogType,
            'name_ru' => $nameRu,
            'name_en' => $nameEn,
            'description_ru' => $descriptionRu,
            'description_en' => $descriptionEn,
            'category' => $category,
            'duration_minutes' => $durationMinutes,
            'buffer_minutes' => $bufferMinutes,
            'formats' => $formats,
            'price_minor' => $priceMinor,
            'price_currency' => $priceCurrency,
            'payment_policy' => $paymentPolicy,
        ];
        $uploadedFile = $this->uploadedFile($attributes);
        $removeImage = (bool) ($attributes['remove_image'] ?? false);
        unset($attributes['service_image'], $attributes['remove_image']);
        $this->assertMediaInput($attributes, $uploadedFile, $removeImage, $organization->getKey());

        $configurationAttributes = $attributes;

        if ($uploadedFile !== null) {
            $configurationAttributes['image_path'] = null;
            $configurationAttributes['external_image_url'] = null;
        }

        $configuration = ServiceConfiguration::from($configurationAttributes);
        $storedPath = null;

        try {
            if ($uploadedFile !== null) {
                $storedPath = $this->media->store($organization->getKey(), $uploadedFile);
                $configurationAttributes['image_path'] = $storedPath;
                $configurationAttributes['external_image_url'] = null;
                $configuration = ServiceConfiguration::from($configurationAttributes);
            }

            return DB::transaction(function () use ($organization, $actor, $configuration): Service {
                $service = new Service;
                $service->forceFill([
                    'organization_id' => $organization->getKey(),
                    ...$configuration->attributes(),
                ]);
                $service->save();

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'service.created',
                    targetType: Service::class,
                    targetId: (string) $service->getKey(),
                    metadata: [
                        'source' => 'application',
                        'is_active' => $configuration->isActive,
                        'has_price' => $configuration->priceMinor !== null,
                    ],
                );

                return $service->refresh();
            });
        } catch (Throwable $exception) {
            $this->discard($organization->getKey(), $storedPath);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    private function uploadedFile(array $attributes): ?UploadedFile
    {
        $value = $attributes['service_image'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! $value instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'service_image' => ['Загрузите изображение в формате JPG или PNG.'],
            ]);
        }

        return $value;
    }

    /** @param array<string, mixed> $attributes */
    private function assertMediaInput(
        array $attributes,
        ?UploadedFile $uploadedFile,
        bool $removeImage,
        int $organizationId,
    ): void {
        $hasPath = $this->hasValue($attributes['image_path'] ?? null);
        $hasExternalUrl = $this->hasValue($attributes['external_image_url'] ?? null);
        $hasConflictingInput = ($uploadedFile !== null && ($hasPath || $hasExternalUrl))
            || ($removeImage && ($uploadedFile !== null || $hasPath || $hasExternalUrl))
            || ($hasPath && $hasExternalUrl);

        if ($hasConflictingInput) {
            throw ValidationException::withMessages([
                'service_image' => ['Выберите файл или HTTPS-ссылку на изображение.'],
                'external_image_url' => ['Выберите файл или HTTPS-ссылку на изображение.'],
            ]);
        }

        if ($hasPath
            && str_starts_with(trim((string) $attributes['image_path']), 'services/')
            && ! $this->media->isManagedPath($organizationId, trim((string) $attributes['image_path']))
        ) {
            throw ValidationException::withMessages([
                'service_image' => ['Выберите изображение или HTTPS-ссылку на изображение.'],
            ]);
        }
    }

    private function hasValue(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
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
