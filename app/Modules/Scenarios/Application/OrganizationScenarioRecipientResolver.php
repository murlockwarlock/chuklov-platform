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
use Illuminate\Database\Eloquent\Collection;

final class OrganizationScenarioRecipientResolver implements ScenarioRecipientResolver
{
    /** @return list<ScenarioRecipient> */
    public function resolve(ScenarioRule $rule, ScenarioEvent $event): array
    {
        $strategy = ScenarioRecipientStrategy::from($rule->recipient_strategy);

        return match ($strategy->type) {
            ScenarioAudienceType::Client => $this->clientRecipient($event),
            ScenarioAudienceType::Members => $this->memberRecipients($event, $this->memberIds($strategy)),
            ScenarioAudienceType::Roles => $this->roleRecipients($event, $strategy),
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
    private function memberRecipients(ScenarioEvent $event, array $userIds): array
    {
        return array_values($this->memberships($event, $userIds)->map(
            fn (OrganizationMembership $membership): ScenarioRecipient => new ScenarioRecipient(
                type: 'internal',
                clientId: null,
                userId: (int) $membership->user_id,
                locale: 'en',
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
            ->whereIn('role', $roles)
            ->orderBy('user_id')
            ->get()
            ->map(fn (OrganizationMembership $membership): ScenarioRecipient => new ScenarioRecipient(
                type: 'internal',
                clientId: null,
                userId: (int) $membership->user_id,
                locale: 'en',
            ))
            ->values()
            ->all());
    }

    /** @param list<int> $userIds
     * @return Collection<int, OrganizationMembership>
     */
    private function memberships(ScenarioEvent $event, array $userIds): Collection
    {
        return OrganizationMembership::query()
            ->where('organization_id', $event->organization_id)
            ->active()
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
