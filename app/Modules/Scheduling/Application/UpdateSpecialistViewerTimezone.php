<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class UpdateSpecialistViewerTimezone
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        Specialist $specialist,
        ?string $timezone,
        string $source = 'manual',
    ): Specialist {
        $organization = $this->context->organization();
        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $source = trim($source);
        if (! in_array($source, ['organization', 'device', 'manual'], true)) {
            throw ValidationException::withMessages(['viewer_timezone_source' => 'The viewer timezone source is invalid.']);
        }

        $normalizedTimezone = $this->timezone($timezone);
        if ($source === 'organization') {
            $normalizedTimezone = null;
        } elseif ($normalizedTimezone === null) {
            throw ValidationException::withMessages(['viewer_timezone' => 'Выберите часовой пояс CRM.']);
        }

        return DB::transaction(function () use ($actor, $specialist, $organization, $normalizedTimezone, $source): Specialist {
            $lockedSpecialist = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($specialist->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $changed = $lockedSpecialist->viewer_timezone !== $normalizedTimezone
                || $lockedSpecialist->viewer_timezone_source !== $source;
            $lockedSpecialist->forceFill([
                'viewer_timezone' => $normalizedTimezone,
                'viewer_timezone_source' => $source,
                'viewer_timezone_suggestion' => null,
            ]);
            $lockedSpecialist->save();

            if ($changed) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'specialist.viewer_timezone.updated',
                    targetType: Specialist::class,
                    targetId: (string) $lockedSpecialist->getKey(),
                    metadata: [
                        'source' => $source,
                        'timezone' => $normalizedTimezone,
                    ],
                );
            }

            return $lockedSpecialist->refresh();
        });
    }

    public function suggest(User $actor, Specialist $specialist, string $timezone): Specialist
    {
        $organization = $this->context->organization();
        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $timezone = $this->timezone($timezone);
        if ($timezone === null) {
            throw ValidationException::withMessages(['viewer_timezone' => 'The device timezone is invalid.']);
        }

        if ($specialist->viewer_timezone_source === 'manual'
            || $this->resolve($specialist) === $timezone) {
            return $specialist;
        }

        $specialist->forceFill(['viewer_timezone_suggestion' => $timezone])->save();

        return $specialist->refresh();
    }

    private function resolve(Specialist $specialist): string
    {
        return app(ResolveSpecialistViewerTimezone::class)->forSpecialist($specialist);
    }

    private function timezone(?string $timezone): ?string
    {
        if ($timezone === null || trim($timezone) === '') {
            return null;
        }

        try {
            return IanaTimezone::from(trim($timezone))->value;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['viewer_timezone' => 'The timezone must be an IANA timezone.']);
        }
    }
}
