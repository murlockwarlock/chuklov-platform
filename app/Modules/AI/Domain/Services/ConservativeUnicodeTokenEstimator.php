<?php

namespace App\Modules\AI\Domain\Services;

use App\Modules\AI\Domain\Contracts\AiTokenEstimatorInterface;

final class ConservativeUnicodeTokenEstimator implements AiTokenEstimatorInterface
{
    public const string NAME = 'unicode-codepoint-conservative-v1';

    public function estimate(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        $characters = mb_strlen($value, 'UTF-8');

        return max(1, intdiv(($characters * 5) + 3, 4));
    }

    public function name(): string
    {
        return self::NAME;
    }
}
