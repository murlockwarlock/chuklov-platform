<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scenarios\Domain\Contracts\ScenarioRecipientResolver;
use App\Modules\Scenarios\Domain\Enums\ScenarioAudienceType;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipient;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipientStrategy;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Collection;

final class OrganizationScenarioRecipientResolver implements ScenarioRecipientResolver
{
    public function __construct(private readonly ScenarioEventPermissionPolicy $permissions) {}

    /** @return list<ScenarioRecipient> */
    public function resolve(ScenarioRule $rule, ScenarioEvent $event): array
    {
        $strategy = ScenarioRecipientStrategy::from($rule->recipient_strategy);

        return match ($strategy->type) {
            ScenarioAudienceType::Client => $this->clientRecipient($event),
            ScenarioAudienceType::Members => $this->memberRecipients($event, $strategy),
            ScenarioAudienceType::Roles => $this->roleRecipients($event, $strategy),
            ScenarioAudienceType::AssignedSpecialist => $this->assignedSpecialistRecipient($event, $strategy),
        };
    }

    /** @return list<ScenarioRecipient> */
    private function clientRecipient(ScenarioEvent $event): array
    {
        $clientId = (int) ($event->payload['client_id'] ?? 0);
        $client = Client::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($clientId)
            ->first();

        if ($client === null) {
            return [];
        }

        return [new ScenarioRecipient(
            type: 'client',
            clientId: (int) $client->getKey(),
            userId: null,
            locale: $this->locale($client->language),
        )];
    }

    /** @param list<int> $userIds
     * @return list<ScenarioRecipient>
     */
    private function memberRecipients(ScenarioEvent $event, ScenarioRecipientStrategy $strategy): array
    {
        return array_values($this->memberships($event, $this->memberIds($strategy))
            ->filter(fn (OrganizationMembership $membership): bool => $this->permissions->allows(
                $event->event_name,
                (int) $event->organization_id,
                $membership,
                $strategy->permission,
            ))
            ->map(
                fn (OrganizationMembership $membership): ScenarioRecipient => new ScenarioRecipient(
                    type: 'internal',
                    clientId: null,
                    userId: (int) $membership->user_id,
                    locale: 'ru',
                ),
            )->values()->all());
    }

    /** @return list<ScenarioRecipient> */
    private function roleRecipients(ScenarioEvent $event, ScenarioRecipientStrategy $strategy): array
    {
        $roles = [];

        foreach ($strategy->values as $role) {
            if ($role instanceof OrganizationRole) {
                $roles[] = $role->value;
            }
        }

        return array_values(OrganizationMembership::query()
            ->where('organization_id', $event->organization_id)
            ->active()
            ->where('notifications_enabled', true)
            ->whereIn('role', $roles)
            ->orderBy('user_id')
            ->get()
            ->filter(fn (OrganizationMembership $membership): bool => $this->permissions->allows(
                $event->event_name,
                (int) $event->organization_id,
                $membership,
                $strategy->permission,
            ))
            ->map(fn (OrganizationMembership $membership): ScenarioRecipient => new ScenarioRecipient(
                type: 'internal',
                clientId: null,
                userId: (int) $membership->user_id,
                locale: 'ru',
            ))
            ->values()
            ->all());
    }

    /** @return list<ScenarioRecipient> */
    private function assignedSpecialistRecipient(ScenarioEvent $event, ScenarioRecipientStrategy $strategy): array
    {
        $specialistId = (int) ($event->payload['specialist_id'] ?? 0);
        $specialist = Specialist::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($specialistId)
            ->first();
        $userId = $specialist?->staff_user_id;

        $membership = $userId === null ? null : OrganizationMembership::query()
            ->where('organization_id', $event->organization_id)
            ->where('user_id', $userId)
            ->active()
            ->where('notifications_enabled', true)
            ->first();

        if ($membership === null || ! $this->permissions->allows(
            $event->event_name,
            (int) $event->organization_id,
            $membership,
            $strategy->permission,
        )) {
            return [];
        }

        if (! $specialist->notifications_enabled) {
            return [];
        }

        return [new ScenarioRecipient(
            type: 'internal',
            clientId: null,
            userId: (int) $userId,
            locale: 'ru',
        )];
    }

    /** @param list<int> $userIds
     * @return Collection<int, OrganizationMembership>
     */
    private function memberships(ScenarioEvent $event, array $userIds): Collection
    {
        return OrganizationMembership::query()
            ->where('organization_id', $event->organization_id)
            ->active()
            ->where('notifications_enabled', true)
            ->whereIn('user_id', $userIds)
            ->orderBy('user_id')
            ->get();
    }

    /** @return list<int> */
    private function memberIds(ScenarioRecipientStrategy $strategy): array
    {
        $ids = [];

        foreach ($strategy->values as $value) {
            if (is_int($value) || is_string($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    private function locale(?string $language): string
    {
        $language = strtolower(trim((string) $language));

        return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language) === 1 ? substr($language, 0, 2) : 'en';
    }
}
