<?php

namespace App\Modules\AI\Application\Actions;

use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;

final class InvalidateAiProviderHealthForCredential
{
    public function handle(int $organizationId, string $provider, int $credentialId): int
    {
        return AiProviderConfiguration::query()
            ->where('organization_id', $organizationId)
            ->where('provider_name', $provider)
            ->where('credential_id', $credentialId)
            ->update([
                'health_status' => ProviderHealthStatus::Unknown,
                'tested_credential_revision' => null,
                'tested_configuration_digest' => null,
                'last_checked_at' => null,
                'last_health_error' => 'Organization credential changed; connection verification is required.',
                'updated_at' => now(),
            ]);
    }
}
