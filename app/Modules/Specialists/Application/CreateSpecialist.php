<?php

namespace App\Modules\Specialists\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Contracts\SpecialistMediaStorageInterface;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistContactProfile;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistNotificationSettings;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class CreateSpecialist
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
        private readonly SyncSpecialistTelegramIdentity $telegramIdentity,
        private readonly NormalizeSpecialistContacts $contactsNormalizer,
        private readonly SpecialistMediaStorageInterface $media,
    ) {}

    /** @param array<string, mixed> $profileAttributes */
    public function handle(
        User $actor,
        string $displayName,
        bool $isActive = true,
        ?string $timezone = null,
        ?int $staffUserId = null,
        ?SpecialistNotificationSettings $notificationSettings = null,
        array $profileAttributes = [],
    ): Specialist {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSpecialists);
        $profile = $this->profile($displayName, $timezone, $profileAttributes);
        $contacts = $this->contacts($profileAttributes, $profile->country);
        $uploadedFile = $this->uploadedFile($profileAttributes);
        $removeProfileImage = (bool) ($profileAttributes['remove_profile_image'] ?? false);
        $this->assertMediaInput($uploadedFile, $removeProfileImage);
        $storedPath = null;

        try {
            if ($uploadedFile !== null) {
                $storedPath = $this->media->store($organization->getKey(), $uploadedFile);
            }

            return DB::transaction(function () use ($actor, $isActive, $organization, $profile, $contacts, $staffUserId, $notificationSettings, $storedPath): Specialist {
                $this->ensureStaffMembership($organization->getKey(), $staffUserId);
                $specialist = new Specialist;
                $specialist->forceFill([
                    'organization_id' => $organization->getKey(),
                    ...$profile->attributes(),
                    ...$contacts->attributes(),
                    'profile_image_path' => $storedPath,
                    'is_active' => $isActive,
                    'notifications_enabled' => $notificationSettings->enabled ?? true,
                    'staff_user_id' => $staffUserId,
                ]);
                $specialist->save();

                if ($notificationSettings?->telegramId !== null) {
                    $this->telegramIdentity->handle(
                        actor: $actor,
                        organization: $organization,
                        specialist: $specialist,
                        telegramId: $notificationSettings->telegramId,
                    );
                }

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'specialist.created',
                    targetType: Specialist::class,
                    targetId: (string) $specialist->getKey(),
                    metadata: [
                        'source' => 'application',
                        'is_active' => $isActive,
                        'timezone_set' => $profile->timezone !== null,
                    ],
                );

                if ($staffUserId !== null) {
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: 'specialist.linked',
                        targetType: Specialist::class,
                        targetId: (string) $specialist->getKey(),
                        metadata: ['user_id' => $staffUserId],
                    );
                }

                return $specialist->refresh();
            });
        } catch (Throwable $exception) {
            $this->discard($organization->getKey(), $storedPath);

            throw $exception;
        }
    }

    private function ensureStaffMembership(int $organizationId, ?int $staffUserId): void
    {
        if ($staffUserId === null) {
            return;
        }

        $isMember = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $staffUserId)
            ->active()
            ->exists();

        if (! $isMember) {
            throw new AuthorizationException('The staff user is not an active member of this organization.');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function profile(string $displayName, ?string $timezone, array $attributes): SpecialistProfile
    {
        return SpecialistProfile::from(
            displayName: $displayName,
            timezone: $timezone,
            specialization: $this->nullableText($attributes['specialization'] ?? null),
            practicesSinceYear: $this->year($attributes['practices_since_year'] ?? null),
            qualification: $this->nullableText($attributes['qualification'] ?? null),
            bio: $this->nullableText($attributes['bio'] ?? null),
            country: $this->nullableText($attributes['country'] ?? null),
            city: $this->nullableText($attributes['city'] ?? null),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function contacts(array $attributes, ?string $country): SpecialistContactProfile
    {
        return $this->contactsNormalizer->handle(
            phone: $attributes['phone'] ?? null,
            email: $attributes['email'] ?? null,
            telegram: $attributes['telegram'] ?? null,
            phoneVisible: $attributes['phone_visible'] ?? false,
            emailVisible: $attributes['email_visible'] ?? false,
            telegramVisible: $attributes['telegram_visible'] ?? false,
            country: $country,
        );
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The specialist profile value is invalid.');
        }

        return $value;
    }

    private function year(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ((is_int($value) || is_string($value)) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }

        throw new InvalidArgumentException('The specialist practice year is invalid.');
    }

    /** @param array<string, mixed> $attributes */
    private function uploadedFile(array $attributes): ?UploadedFile
    {
        $value = $attributes['profile_image'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (! $value instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'profile_image' => ['Не удалось получить загруженную фотографию.'],
            ]);
        }

        return $value;
    }

    private function assertMediaInput(?UploadedFile $uploadedFile, bool $removeProfileImage): void
    {
        if ($uploadedFile !== null && $removeProfileImage) {
            throw ValidationException::withMessages([
                'profile_image' => ['Выберите новую фотографию или удалите текущую, но не оба действия сразу.'],
            ]);
        }
    }

    private function discard(int $organizationId, ?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            $this->media->deleteManaged($organizationId, $path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
