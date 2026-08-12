<?php

namespace App\Modules\Security\Application;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use Illuminate\Support\Str;

class RecordAuditEvent
{
    private const SENSITIVE_KEY_PATTERN = '/secret|token|password|credential_value|authorization|cookie|session|medical|content|body/i';

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
            'metadata' => $this->sanitize($metadata),
            'occurred_at' => now(),
        ]);
        $event->save();

        return $event->refresh();
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    private function sanitize(array $metadata): array
    {
        $safe = [];

        foreach ($metadata as $key => $value) {
            $key = (string) $key;

            if (preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                $safe[$key] = '[REDACTED]';

                continue;
            }

            $safe[$key] = is_array($value)
                ? $this->sanitize($value)
                : (is_string($value) ? Str::limit($value, 255, '…') : $value);
        }

        return $safe;
    }
}
