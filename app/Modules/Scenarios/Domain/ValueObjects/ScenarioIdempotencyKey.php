<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

final readonly class ScenarioIdempotencyKey
{
    public static function materialization(
        int $organizationId,
        int $eventId,
        int $ruleId,
        string $recipientKey,
        int $sequenceNumber = 1,
    ): string {
        $parts = [$organizationId, $eventId, $ruleId, $recipientKey];

        if ($sequenceNumber > 1) {
            $parts[] = $sequenceNumber;
        }

        return hash('sha256', implode('|', $parts));
    }

    public static function delivery(int $organizationId, int $actionId, string $channel): string
    {
        return hash('sha256', implode('|', [$organizationId, $actionId, $channel]));
    }
}
