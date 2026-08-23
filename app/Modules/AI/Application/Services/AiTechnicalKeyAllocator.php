<?php

namespace App\Modules\AI\Application\Services;

use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\ValueObjects\AiTechnicalKey;

final class AiTechnicalKeyAllocator
{
    public function prompt(int $organizationId, string $name, mixed $requestedKey): string
    {
        return $this->allocate(
            organizationId: $organizationId,
            requestedKey: $requestedKey,
            baseKey: AiTechnicalKey::fromHumanName($name, 'prompt'),
            model: AiPrompt::class,
        );
    }

    public function evaluation(int $organizationId, string $name, mixed $requestedKey): string
    {
        return $this->allocate(
            organizationId: $organizationId,
            requestedKey: $requestedKey,
            baseKey: AiTechnicalKey::fromHumanName($name, 'evaluation'),
            model: AiEvalSuite::class,
        );
    }

    /** @param class-string<AiPrompt|AiEvalSuite> $model */
    private function allocate(int $organizationId, mixed $requestedKey, string $baseKey, string $model): string
    {
        if ($requestedKey !== null && $requestedKey !== '') {
            return AiTechnicalKey::normalize($requestedKey);
        }

        $key = $baseKey;
        $suffix = 2;
        while ($model::query()
            ->where('organization_id', $organizationId)
            ->where('key', $key)
            ->exists()) {
            $key = mb_substr($baseKey, 0, 80 - mb_strlen((string) $suffix) - 1).'-'.$suffix;
            $suffix++;
        }

        return AiTechnicalKey::normalize($key);
    }
}
