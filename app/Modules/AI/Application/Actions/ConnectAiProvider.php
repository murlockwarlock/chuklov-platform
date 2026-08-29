<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\ReplaceOrganizationCredential;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ConnectAiProvider
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly ReplaceOrganizationCredential $replaceCredential,
        private readonly CreateAiProviderConfiguration $createProvider,
        private readonly UpdateAiProviderConfiguration $updateProvider,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): AiProviderConfiguration
    {
        return DB::transaction(function () use ($actor, $data): AiProviderConfiguration {
            $providerName = AiProviderCatalog::normalize($data['provider_name'] ?? null);
            $data['credential_id'] = $this->credentialId($actor, $providerName, $data, null);
            unset($data['api_key']);

            return $this->createProvider->handle($actor, $data);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, AiProviderConfiguration $provider, array $data): AiProviderConfiguration
    {
        return DB::transaction(function () use ($actor, $provider, $data): AiProviderConfiguration {
            $providerName = AiProviderCatalog::normalize($provider->provider_name);
            $data['credential_id'] = $this->credentialId($actor, $providerName, $data, $provider);
            unset($data['api_key']);

            return $this->updateProvider->handle($actor, $provider, $data);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    private function credentialId(
        User $actor,
        string $providerName,
        array $data,
        ?AiProviderConfiguration $existing,
    ): ?int {
        $apiKey = $data['api_key'] ?? null;
        if (is_string($apiKey) && trim($apiKey) !== '') {
            $credential = $existing === null || $existing->credential_id === null
                ? null
                : OrganizationCredential::query()
                    ->where('organization_id', $this->context->id())
                    ->whereKey($existing->credential_id)
                    ->first();
            $credentialName = $credential instanceof OrganizationCredential && $credential->provider === $providerName
                ? $credential->credential_name
                : AiProviderCatalog::label($providerName).' API-ключ';

            return $this->replaceCredential->handle(
                actor: $actor,
                provider: $providerName,
                credentialName: $credentialName,
                credentials: ['api_key' => $apiKey],
            )->getKey();
        }

        if (array_key_exists('credential_id', $data) && $data['credential_id'] !== null && $data['credential_id'] !== '') {
            return self::positiveId($data['credential_id']);
        }

        return $existing?->credential_id;
    }

    private static function positiveId(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new InvalidArgumentException('The selected organization credential is invalid.');
    }
}
