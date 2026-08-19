<?php

namespace App\Modules\Services\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Application\EnsureScheduleMutationImpactAcknowledged;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Services\Domain\Contracts\ServiceMediaStorageInterface;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Services\Domain\ValueObjects\ServiceConfiguration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateService
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly ScheduleMutationImpactCalculator $impactCalculator,
        private readonly EnsureScheduleMutationImpactAcknowledged $impactAcknowledgement,
        private readonly RecordAuditEvent $audit,
        private readonly ServiceMediaStorageInterface $media,
    ) {}

    /**
     * @param  string|array<string, mixed>  $name
     * @param  list<string>  $formats
     */
    public function handle(
        User $actor,
        Service $service,
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
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
    ): Service {
        $organization = $this->context->organization();

        if ((int) $service->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The service is outside the current organization.');
        }

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
        $mediaMode = $this->mediaMode($attributes, $uploadedFile, $removeImage, $service);
        $requestedImagePath = $attributes['image_path'] ?? null;
        $requestedExternalUrl = $attributes['external_image_url'] ?? null;
        unset($attributes['service_image'], $attributes['remove_image']);
        $attributes = $this->attributesForValidation(
            $attributes,
            $service,
            $mediaMode,
            $requestedImagePath,
            $requestedExternalUrl,
        );
        $this->assertMediaInput($attributes, $uploadedFile, $removeImage, $organization->getKey());
        $configuration = ServiceConfiguration::from($attributes);
        $validatedImagePath = $configuration->imagePath;
        $validatedExternalUrl = $configuration->externalImageUrl;
        $storedPath = null;

        try {
            if ($uploadedFile !== null) {
                $storedPath = $this->media->store($organization->getKey(), $uploadedFile);
            }

            return DB::transaction(function () use (
                $actor,
                $configuration,
                $organization,
                $service,
                $mediaMode,
                $validatedExternalUrl,
                $storedPath,
                $validatedImagePath,
                $acknowledgeImpact,
                $acknowledgedImpactDigest,
            ): Service {
                $lockedService = Service::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($service->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $finalAttributes = $configuration->attributes();
                [$finalAttributes['image_path'], $finalAttributes['external_image_url']] = $this->mediaAttributes(
                    $lockedService,
                    $mediaMode,
                    $validatedExternalUrl,
                    $storedPath,
                    $validatedImagePath,
                );
                $finalConfiguration = ServiceConfiguration::from($finalAttributes);
                $impact = $this->impactCalculator->forServiceChange($lockedService, $finalConfiguration->attributes());

                $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);
                $changedFields = [];

                foreach ($finalConfiguration->attributes() as $field => $value) {
                    if ($lockedService->getAttribute($field) !== $value) {
                        $changedFields[] = $field;
                    }
                }

                $oldImagePath = $lockedService->getAttribute('image_path');
                $lockedService->forceFill($finalConfiguration->attributes());
                $lockedService->save();

                if (is_string($oldImagePath)
                    && $oldImagePath !== $finalConfiguration->imagePath
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
                        action: 'service.updated',
                        targetType: Service::class,
                        targetId: (string) $lockedService->getKey(),
                        metadata: [
                            'fields' => implode(',', $changedFields),
                            'is_active' => $finalConfiguration->isActive,
                            'has_price' => $finalConfiguration->priceMinor !== null,
                        ],
                    );
                }

                if ($impact->hasConflicts()) {
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: 'schedule.mutation.acknowledged',
                        targetType: Service::class,
                        targetId: (string) $lockedService->getKey(),
                        metadata: [
                            'source' => 'crm',
                            'mutation' => 'service_change',
                            'affected_booking_count' => $impact->count(),
                            'impact_digest' => $impact->digest,
                        ],
                    );
                }

                return $lockedService->refresh();
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
    private function mediaMode(
        array $attributes,
        ?UploadedFile $uploadedFile,
        bool $removeImage,
        Service $service,
    ): string {
        $hasPath = $this->hasValue($attributes['image_path'] ?? null);
        $hasExternalUrl = $this->hasValue($attributes['external_image_url'] ?? null);
        $currentExternalUrl = $service->getAttribute('external_image_url');

        if (($uploadedFile !== null || $removeImage)
            && $hasExternalUrl
            && is_string($currentExternalUrl)
            && trim($currentExternalUrl) === trim((string) $attributes['external_image_url'])
        ) {
            $hasExternalUrl = false;
        }

        if (($uploadedFile !== null && ($hasPath || $hasExternalUrl))
            || ($removeImage && ($uploadedFile !== null || $hasPath))
            || ($hasPath && $hasExternalUrl)
        ) {
            throw ValidationException::withMessages([
                'service_image' => ['Выберите файл или HTTPS-ссылку на изображение.'],
                'external_image_url' => ['Выберите файл или HTTPS-ссылку на изображение.'],
            ]);
        }

        if ($uploadedFile !== null) {
            return 'upload';
        }

        if ($removeImage) {
            return 'remove';
        }

        if ($hasExternalUrl) {
            return 'external';
        }

        if (array_key_exists('external_image_url', $attributes)) {
            return 'clear_external';
        }

        if ($hasPath) {
            return 'path';
        }

        return 'preserve';
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function attributesForValidation(
        array $attributes,
        Service $service,
        string $mediaMode,
        mixed $requestedImagePath,
        mixed $requestedExternalUrl,
    ): array {
        [$imagePath, $externalImageUrl] = $this->mediaAttributes(
            $service,
            $mediaMode,
            $requestedExternalUrl,
            null,
            is_string($requestedImagePath) ? $requestedImagePath : null,
        );
        $attributes['image_path'] = $imagePath;
        $attributes['external_image_url'] = $externalImageUrl;

        return $attributes;
    }

    /** @return array{0: mixed, 1: mixed} */
    private function mediaAttributes(
        Service $service,
        string $mediaMode,
        mixed $requestedExternalUrl,
        ?string $storedPath,
        ?string $requestedImagePath,
    ): array {
        return match ($mediaMode) {
            'upload' => [$storedPath, null],
            'remove' => [null, null],
            'external' => [null, $requestedExternalUrl],
            'clear_external' => [
                is_string($service->getAttribute('image_path')) ? $service->getAttribute('image_path') : null,
                null,
            ],
            'path' => [
                $requestedImagePath,
                null,
            ],
            default => [
                is_string($service->getAttribute('image_path')) ? $service->getAttribute('image_path') : null,
                is_string($service->getAttribute('external_image_url')) ? $service->getAttribute('external_image_url') : null,
            ],
        };
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

        if (($uploadedFile !== null && ($hasPath || $hasExternalUrl))
            || ($removeImage && ($uploadedFile !== null || $hasPath || $hasExternalUrl))
            || ($hasPath && $hasExternalUrl)
        ) {
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
