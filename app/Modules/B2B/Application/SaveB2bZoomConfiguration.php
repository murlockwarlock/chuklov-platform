<?php

namespace App\Modules\B2B\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\ReplaceOrganizationCredential;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveB2bZoomConfiguration
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly ReplaceOrganizationCredential $replaceCredential,
    ) {}

    public function handle(
        User $actor,
        string $accountId,
        string $clientId,
        ?string $clientSecret,
        string $hostUserId,
        bool $enabled,
    ): OrganizationCredential {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageCredentials);
        $accountId = $this->required($accountId, 'account_id');
        $clientId = $this->required($clientId, 'client_id');
        $hostUserId = $this->required($hostUserId, 'host_user_id');
        $clientSecret = $clientSecret === null ? null : trim($clientSecret);

        return DB::transaction(function () use ($actor, $accountId, $clientId, $clientSecret, $hostUserId, $enabled, $organization): OrganizationCredential {
            $current = OrganizationCredential::query()
                ->where('organization_id', $organization->getKey())
                ->where('provider', 'zoom')
                ->where('credential_name', (string) config('b2b.credential_name'))
                ->lockForUpdate()
                ->first();
            $existingCredentials = is_array($current?->credentials) ? $current->credentials : [];
            $existingSecret = $existingCredentials['client_secret'] ?? null;

            if ($clientSecret === null || $clientSecret === '') {
                if (! is_string($existingSecret) || trim($existingSecret) === '') {
                    throw ValidationException::withMessages(['client_secret' => 'Введите Client Secret из приложения Zoom.']);
                }

                $clientSecret = $existingSecret;
            }

            if (mb_strlen($clientSecret) > 2048) {
                throw ValidationException::withMessages(['client_secret' => 'Client Secret слишком длинный.']);
            }

            return $this->replaceCredential->handle(
                actor: $actor,
                provider: 'zoom',
                credentialName: (string) config('b2b.credential_name'),
                credentials: [
                    'account_id' => $accountId,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'host_user_id' => $hostUserId,
                ],
                status: $enabled ? CredentialStatus::Active : CredentialStatus::Disabled,
            );
        }, attempts: 3);
    }

    private function required(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 255) {
            throw ValidationException::withMessages([$field => 'Заполните поле корректно.']);
        }

        return $value;
    }
}
