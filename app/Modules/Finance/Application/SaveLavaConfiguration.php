<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Security\Application\ReplaceOrganizationCredential;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Validation\ValidationException;

final class SaveLavaConfiguration
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly ReplaceOrganizationCredential $replaceCredential,
    ) {}

    public function handle(
        User $actor,
        ?string $apiKey,
        ?string $webhookKey,
        bool $enabled,
    ): ?OrganizationCredential {
        $organization = $this->authorization->authorizeManage($actor);
        $credentialName = (string) config('payments.lava.credential_name', 'default');
        $current = OrganizationCredential::query()
            ->where('organization_id', $organization->getKey())
            ->where('provider', 'lava')
            ->where('credential_name', $credentialName)
            ->first();
        $apiKey = $this->normalize($apiKey);
        $webhookKey = $this->normalize($webhookKey);
        $currentApiKey = $this->credentialValue($current, 'api_key');

        if (! $enabled && $current === null) {
            return null;
        }

        if ($apiKey === null
            && $webhookKey === null
            && $current !== null
            && $enabled === ($current->status === CredentialStatus::Active)
            && (! $enabled || $currentApiKey !== null)) {
            return $current;
        }

        return $this->replaceCredential->handle(
            actor: $actor,
            provider: 'lava',
            credentialName: $credentialName,
            credentials: function (?OrganizationCredential $current) use ($apiKey, $webhookKey): array {
                $resolvedApiKey = $apiKey ?? $this->credentialValue($current, 'api_key');

                if ($resolvedApiKey === null) {
                    throw ValidationException::withMessages([
                        'lava_api_key' => 'Введите API-ключ Lava.',
                    ]);
                }

                $this->assertSecretLength($resolvedApiKey, 'lava_api_key');
                $resolvedWebhookKey = $webhookKey ?? $this->credentialValue($current, 'webhook_api_key');
                $credentials = ['api_key' => $resolvedApiKey];

                if ($resolvedWebhookKey !== null) {
                    $this->assertSecretLength($resolvedWebhookKey, 'lava_webhook_key');
                    $credentials['webhook_api_key'] = $resolvedWebhookKey;
                }

                return $credentials;
            },
            status: $enabled ? CredentialStatus::Active : CredentialStatus::Disabled,
        );
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function credentialValue(?OrganizationCredential $credential, string $key): ?string
    {
        $value = $credential?->credentials[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function assertSecretLength(string $value, string $field): void
    {
        if (mb_strlen($value) > 2048) {
            throw ValidationException::withMessages([
                $field => 'Значение ключа слишком длинное.',
            ]);
        }
    }
}
