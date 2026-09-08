<?php

namespace App\Modules\AI\Domain\Contracts;

interface AiTokenEstimatorInterface
{
    public function estimate(string $value): int;

    public function name(): string;
}
