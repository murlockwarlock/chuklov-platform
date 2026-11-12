<?php

namespace App\Modules\Specialists\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scheduling\Application\EnsureScheduleMutationImpactAcknowledged;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
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

class UpdateSpecialist
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly ScheduleMutationImpactCalculator $impactCalculator,
        private readonly EnsureScheduleMutationImpactAcknowledged $impactAcknowledgement,
        private readonly RecordAuditEvent $audit,
        private readonly NormalizeSpecialistContacts $contactsNormalizer,
        private readonly SpecialistMediaStorageInterface $media,
    ) {}

    /** @param array<string, mixed> $profileAttributes */
    public function handle(
        User $actor,
        Specialist $specialist,
        string $displayName,
        bool $isActive,
        ?string $timezone = null,
        ?int $staffUserId = null,
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
        ?SpecialistNotificationSettings $notificationSettings = null,
        array $profileAttributes = [],
    ): Specialist {
        $organization = $this->context->organization();

        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSpecialists);
        $profile = $this->profile($displayName, $timezone, $profileAttributes, $specialist);
        $contacts = $this->contacts($profileAttributes, $specialist, $profile->country);
        $uploadedFile = $this->uploadedFile($profileAttributes);
        $removeProfileImage = (bool) ($profileAttributes['remove_profile_image'] ?? false);
        $this->assertMediaInput($uploadedFile, $removeProfileImage);
        $storedPath = null;

        try {
            if ($uploadedFile !== null) {
                $storedPath = $this->media->store($organization->getKey(), $uploadedFile);
            }

            return DB::transaction(function () use ($actor, $isActive, $organization, $profile, $contacts, $specialist, $staffUserId, $acknowledgeImpact, $acknowledgedImpactDigest, $notificationSettings, $removeProfileImage, $storedPath): Specialist {
                $lockedSpecialist = Specialist::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($specialist->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->ensureStaffMembership($organization->getKey(), $staffUserId);
                $oldStaffUserId = $lockedSpecialist->staff_user_id;
                $oldIsActive = (bool) $lockedSpecialist->is_active;
                $oldImagePath = $lockedSpecialist->getAttribute('profile_image_path');
                $profileImagePath = is_string($oldImagePath) ? $oldImagePath : null;
                if ($storedPath !== null) {
                    $profileImagePath = $storedPath;
                } elseif ($removeProfileImage) {
                    $profileImagePath = null;
                }

                /** @var list<string> $changedFields */
                $changedFields = [];
                foreach ([...$profile->attributes(), ...$contacts->attributes(), 'profile_image_path' => $profileImagePath] as $field => $value) {
                    if ($lockedSpecialist->getAttribute($field) !== $value) {
                        $changedFields[] = $field;
                    }
                }

                if ($oldIsActive !== $isActive) {
                    $changedFields[] = 'is_active';
                }

                if ((int) ($oldStaffUserId ?? 0) !== (int) ($staffUserId ?? 0)) {
                    $changedFields[] = 'staff_user_id';
                }

                if ($notificationSettings !== null
                    && (bool) $lockedSpecialist->notifications_enabled !== $notificationSettings->enabled) {
                    $changedFields[] = 'notifications_enabled';
                }

                $impact = $this->impactCalculator->forSpecialistChange(
                    specialist: $lockedSpecialist,
                    newIsActive: $isActive,
                    newTimezone: $profile->timezone,
                );

                $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);

                $lockedSpecialist->forceFill([
                    ...$profile->attributes(),
                    ...$contacts->attributes(),
                    'profile_image_path' => $profileImagePath,
                    'is_active' => $isActive,
                    'notifications_enabled' => $notificationSettings->enabled ?? $lockedSpecialist->notifications_enabled,
                    'staff_user_id' => $staffUserId,
                ]);
                $lockedSpecialist->save();

                if (is_string($oldImagePath) && $oldImagePath !== $profileImagePath
                    && $this->media->isManagedPath($organization->getKey(), $oldImagePath)) {
                    DB::afterCommit(function () use ($organization, $oldImagePath): void {
                        try {
                            $this->media->deleteManaged($organization->getKey(), $oldImagePath);
                        } catch (Throwable $cleanupException) {
                            report($cleanupException);
                        }
                    });
                }

                $profileChangedFields = array_values(array_diff($changedFields, [
                    'is_active',
                    'notifications_enabled',
                    'staff_user_id',
                ]));
                if ($profileChangedFields !== []) {
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: 'specialist.updated',
                        targetType: Specialist::class,
                        targetId: (string) $lockedSpecialist->getKey(),
                        metadata: ['fields' => implode(',', $profileChangedFields)],
                    );
                }

                if ($oldIsActive !== $isActive) {
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: $isActive ? 'specialist.activated' : 'specialist.deactivated',
                        targetType: Specialist::class,
                        targetId: (string) $lockedSpecialist->getKey(),
                        metadata: ['source' => 'crm'],
                    );
                }

                if (in_array('notifications_enabled', $changedFields, true)) {
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: 'specialist.notifications.updated',
                        targetType: Specialist::class,
                        targetId: (string) $lockedSpecialist->getKey(),
                        metadata: ['enabled' => $notificationSettings?->enabled],
                    );
                }

                if ($impact->hasConflicts()) {
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: 'schedule.mutation.acknowledged',
                        targetType: Specialist::class,
                        targetId: (string) $lockedSpecialist->getKey(),
                        metadata: [
                            'source' => 'crm',
                            'mutation' => $this->impactMutationLabel($oldIsActive, $isActive, $changedFields),
                            'affected_booking_count' => $impact->count(),
                            'impact_digest' => $impact->digest,
                        ],
                    );
                }

                if ((int) ($oldStaffUserId ?? 0) !== (int) ($staffUserId ?? 0)) {
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: $staffUserId === null ? 'specialist.unlinked' : 'specialist.linked',
                        targetType: Specialist::class,
                        targetId: (string) $lockedSpecialist->getKey(),
                        metadata: $staffUserId === null ? [] : ['user_id' => $staffUserId],
                    );
                }

                return $lockedSpecialist->refresh();
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
    private function profile(string $displayName, ?string $timezone, array $attributes, Specialist $fallback): SpecialistProfile
    {
        return SpecialistProfile::from(
            displayName: $displayName,
            timezone: $timezone,
            specialization: $this->nullableText($this->value($attributes, 'specialization', $fallback->specialization)),
            practicesSinceYear: $this->year($this->value($attributes, 'practices_since_year', $fallback->practices_since_year)),
            qualification: $this->nullableText($this->value($attributes, 'qualification', $fallback->qualification)),
            bio: $this->nullableText($this->value($attributes, 'bio', $fallback->bio)),
            country: $this->nullableText($this->value($attributes, 'country', $fallback->country)),
            city: $this->nullableText($this->value($attributes, 'city', $fallback->city)),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function contacts(array $attributes, Specialist $fallback, ?string $country): SpecialistContactProfile
    {
        return $this->contactsNormalizer->handle(
            phone: $this->value($attributes, 'phone', $fallback->phone),
            email: $this->value($attributes, 'email', $fallback->email),
            telegram: $this->value($attributes, 'telegram', $fallback->telegram),
            phoneVisible: $this->value($attributes, 'phone_visible', $fallback->phone_visible),
            emailVisible: $this->value($attributes, 'email_visible', $fallback->email_visible),
            telegramVisible: $this->value($attributes, 'telegram_visible', $fallback->telegram_visible),
            country: $country,
        );
    }

    /** @param array<string, mixed> $attributes */
    private function value(array $attributes, string $field, mixed $fallback): mixed
    {
        return array_key_exists($field, $attributes) ? $attributes[$field] : $fallback;
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

    /** @param list<string> $changedFields */
    private function impactMutationLabel(bool $oldIsActive, bool $newIsActive, array $changedFields): string
    {
        $timezoneChanged = in_array('timezone', $changedFields, true);
        $deactivated = $oldIsActive && ! $newIsActive;

        return match (true) {
            $deactivated && $timezoneChanged => 'specialist_deactivation_and_timezone',
            $deactivated => 'specialist_deactivation',
            $timezoneChanged => 'specialist_timezone',
            default => 'specialist_schedule_change',
        };
    }
}
