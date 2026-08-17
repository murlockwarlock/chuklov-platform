<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\AI\Domain\Models\AiRunAttempt;

interface AiSafetyBudgetManagerInterface
{
    public function reserveBudget(int $organizationId, int $requestedMinorUnits): void;

    public function settleBudget(
        int $organizationId,
        string $usageDate,
        int $reservedMinorUnits,
        int $settledMinorUnits,
    ): void;

    public function releaseBudget(int $organizationId, string $usageDate, int $reservedMinorUnits): void;

    public function chargeConservatively(int $organizationId, string $usageDate, int $reservedMinorUnits): void;

    public function settleAttemptBudget(AiRunAttempt $attempt, int $settledMinorUnits): int;

    public function releaseAttemptBudget(AiRunAttempt $attempt): void;

    public function chargeAttemptConservatively(AiRunAttempt $attempt): void;
}
