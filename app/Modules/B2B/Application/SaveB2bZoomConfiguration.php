<?php

namespace App\Modules\B2B\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\ReplaceOrganizationCredential;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
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

        return $this->replaceCredential->handle(
            actor: $actor,
            provider: 'zoom',
            credentialName: (string) config('b2b.credential_name'),
            credentials: function (?OrganizationCredential $current) use ($accountId, $clientId, $clientSecret, $hostUserId): array {
                $existingCredentials = is_array($current?->credentials) ? $current->credentials : [];
                $existingSecret = $existingCredentials['client_secret'] ?? null;
                $resolvedSecret = $clientSecret;

                if ($resolvedSecret === null || $resolvedSecret === '') {
                    if (! is_string($existingSecret) || trim($existingSecret) === '') {
                        throw ValidationException::withMessages(['client_secret' => 'Введите Client Secret из приложения Zoom.']);
                    }

                    $resolvedSecret = $existingSecret;
                }

                if (mb_strlen($resolvedSecret) > 2048) {
                    throw ValidationException::withMessages(['client_secret' => 'Client Secret слишком длинный.']);
                }

                return [
                    'account_id' => $accountId,
                    'client_id' => $clientId,
                    'client_secret' => $resolvedSecret,
                    'host_user_id' => $hostUserId,
                ];
            },
            status: $enabled ? CredentialStatus::Active : CredentialStatus::Disabled,
        );
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
