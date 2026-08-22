<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use InvalidArgumentException;

final class CreateAiProviderConfiguration
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): AiProviderConfiguration
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiProviders);

        $providerName = AiProviderCatalog::normalize($data['provider_name'] ?? null);
        $displayName = self::displayName($data['display_name'] ?? null);
        $credentialId = self::credentialId($data['credential_id'] ?? null);
        $credential = null;

        if ($credentialId !== null) {
            $credential = OrganizationCredential::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($credentialId)
                ->first();

            if ($credential === null || $credential->provider !== $providerName) {
                throw new InvalidArgumentException('The selected organization credential is invalid for this provider.');
            }
        }

        $options = (array) ($data['options'] ?? []);
        try {
            $options = AiProviderExecutionConfiguration::normalizeOptions($providerName, $options);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        if (AiProviderConfiguration::query()
            ->where('organization_id', $organization->getKey())
            ->where('provider_name', $providerName)
            ->exists()) {
            throw new InvalidArgumentException('This AI provider is already configured for the organization.');
        }

        $provider = AiProviderConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_name' => $providerName,
            'display_name' => $displayName,
            'is_enabled' => array_key_exists('is_enabled', $data) ? (bool) $data['is_enabled'] : true,
            'credential_id' => $credential?->getKey(),
            'options' => $options,
        ]);

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'ai.provider_config.created',
            targetType: AiProviderConfiguration::class,
            targetId: (string) $provider->getKey(),
            metadata: [
                'provider_name' => $provider->provider_name,
                'is_enabled' => $provider->is_enabled,
                'credential_configured' => $provider->credential_id !== null,
            ],
        );

        return $provider;
    }

    private static function displayName(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Provider display name is required.');
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 200) {
            throw new InvalidArgumentException('Provider display name is invalid.');
        }

        return $value;
    }

    private static function credentialId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;

            return $id > 0 ? $id : null;
        }

        throw new InvalidArgumentException('The selected organization credential is invalid.');
    }
}
