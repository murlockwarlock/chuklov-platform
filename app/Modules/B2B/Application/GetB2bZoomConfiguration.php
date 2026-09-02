<?php

namespace App\Modules\B2B\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;

final class GetB2bZoomConfiguration
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** @return array{exists: bool, enabled: bool, configured: bool, accountId: string|null, clientId: string|null, hostUserId: string|null, hasClientSecret: bool, status: string|null} */
    public function handle(): array
    {
        $credential = OrganizationCredential::query()
            ->where('organization_id', $this->context->id())
            ->where('provider', 'zoom')
            ->where('credential_name', (string) config('b2b.credential_name'))
            ->first();
        $credentials = is_array($credential?->credentials) ? $credential->credentials : [];
        $accountId = $this->value($credentials, 'account_id');
        $clientId = $this->value($credentials, 'client_id');
        $hostUserId = $this->value($credentials, 'host_user_id');
        $hasClientSecret = $this->value($credentials, 'client_secret') !== null;
        $enabled = $credential?->status === CredentialStatus::Active;

        return [
            'exists' => $credential !== null,
            'enabled' => $enabled,
            'configured' => $enabled
                && $accountId !== null
                && $clientId !== null
                && $hostUserId !== null
                && $hasClientSecret,
            'accountId' => $accountId,
            'clientId' => $clientId,
            'hostUserId' => $hostUserId,
            'hasClientSecret' => $hasClientSecret,
            'status' => $credential?->status?->value,
        ];
    }

    /** @param array<string, mixed> $credentials */
    private function value(array $credentials, string $key): ?string
    {
        $value = $credentials[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
