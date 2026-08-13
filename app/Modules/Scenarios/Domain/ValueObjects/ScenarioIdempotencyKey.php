<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

final readonly class ScenarioIdempotencyKey
{
    public static function materialization(int $organizationId, int $eventId, int $ruleId, string $recipientKey): string
    {
        return hash('sha256', implode('|', [$organizationId, $eventId, $ruleId, $recipientKey]));
    }

    public static function delivery(int $organizationId, int $actionId, string $channel): string
    {
        return hash('sha256', implode('|', [$organizationId, $actionId, $channel]));
    }
}
