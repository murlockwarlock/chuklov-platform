<?php

namespace App\Modules\Finance\Infrastructure\Lava;

use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Http\Request;

final class LavaWebhookAuthenticator
{
    public function authenticate(Request $request): ?int
    {
        $provided = $request->header('X-Api-Key');
        if (! is_string($provided) || trim($provided) === '') {
            return null;
        }

        $matches = [];
        $credentials = OrganizationCredential::query()
            ->where('provider', 'lava')
            ->where('status', CredentialStatus::Active->value)
            ->get(['id', 'organization_id', 'credentials']);
        foreach ($credentials as $credential) {
            $expected = $credential->credentials['webhook_api_key'] ?? null;
            if (is_string($expected) && $expected !== '' && hash_equals($expected, trim($provided))) {
                $matches[] = (int) $credential->organization_id;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }
}
