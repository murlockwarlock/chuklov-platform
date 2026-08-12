<?php

namespace App\Modules\Security\Application;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use Illuminate\Support\Str;

class RecordAuditEvent
{
    /** @var array<string, list<string>> */
    private const ALLOWED_METADATA_KEYS = [
        'organization.setting.updated' => ['setting_key', 'value_type'],
        'organization.feature.updated' => ['feature_key', 'enabled'],
        'organization.credential.replaced' => ['provider', 'credential_name', 'status'],
        'client.created' => ['source'],
        'client.profile.updated' => ['source', 'fields'],
        'client.channel_identity.registered' => ['channel', 'verification_status'],
        'client.channel_identity.verified' => ['channel', 'verification_method'],
        'client.consent.recorded' => ['subject', 'version', 'granted', 'evidence'],
    ];

    /** @param array<array-key, mixed> $metadata */
    public function handle(
        Organization $organization,
        ?User $actor,
        string $action,
        ?string $targetType,
        ?string $targetId,
        array $metadata = [],
    ): AuditEvent {
        $event = new AuditEvent;
        $event->forceFill([
            'organization_id' => $organization->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $this->sanitize($action, $metadata),
            'occurred_at' => now(),
        ]);
        $event->save();

        return $event->refresh();
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    private function sanitize(string $action, array $metadata): array
    {
        $allowedKeys = self::ALLOWED_METADATA_KEYS[$action] ?? [];
        $safe = [];

        foreach ($metadata as $key => $value) {
            $key = (string) $key;

            if (! in_array($key, $allowedKeys, true) || (! is_scalar($value) && $value !== null)) {
                continue;
            }

            $safe[$key] = is_string($value) ? Str::limit($value, 128, '…') : $value;
        }

        return $safe;
    }
}
