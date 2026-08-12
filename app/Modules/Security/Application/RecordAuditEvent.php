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
        'client.self_booking.blocked' => ['source'],
        'client.self_booking.unblocked' => ['source'],
        'service.created' => ['source', 'is_active', 'has_price'],
        'service.updated' => ['fields', 'is_active', 'has_price'],
        'specialist.created' => ['source', 'is_active', 'timezone_set'],
        'specialist.updated' => ['fields'],
        'specialist.activated' => ['source'],
        'specialist.deactivated' => ['source'],
        'specialist.linked' => ['user_id'],
        'specialist.unlinked' => [],
        'content.section.created' => ['section_key', 'locale', 'is_visible'],
        'content.section.updated' => ['section_key', 'locale', 'is_visible'],
        'legal.document.published' => ['document_type', 'version', 'locale', 'management_mode'],
        'legal.document.draft.updated' => ['document_type', 'version', 'locale'],
        'organization.scheduling.lead_time.updated' => ['minutes'],
        'specialist.schedule.updated' => ['weekday_count', 'interval_count'],
        'specialist.schedule.exception.created' => ['exception_type', 'source'],
        'specialist.schedule.exception.deleted' => ['source'],
        'specialist.unavailable_period.created' => ['source'],
        'specialist.unavailable_period.deleted' => ['source'],
        'booking.created' => ['source', 'visit_format', 'status'],
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
