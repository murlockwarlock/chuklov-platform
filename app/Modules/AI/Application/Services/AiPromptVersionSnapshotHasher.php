<?php

namespace App\Modules\AI\Application\Services;

use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\ValueObjects\AiParameterConfig;

final class AiPromptVersionSnapshotHasher
{
    public function forVersion(AiPromptVersion $version): string
    {
        return hash('sha256', json_encode([
            'id' => (int) $version->getKey(),
            'prompt_id' => (int) $version->prompt_id,
            'version' => (int) $version->version,
            'system_prompt' => $version->system_prompt,
            'user_prompt_template' => $version->user_prompt_template,
            'parameter_config' => AiParameterConfig::fromArray((array) $version->parameter_config)->toArray(),
            'change_notes' => $version->change_notes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
