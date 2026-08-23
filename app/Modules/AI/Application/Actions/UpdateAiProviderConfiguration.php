<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Exceptions\AiProviderProbeUnsupportedException;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class UpdateAiProviderConfiguration
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, AiProviderConfiguration $providerConfig, array $data): AiProviderConfiguration
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiProviders);

        if ((int) $providerConfig->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('Provider configuration is outside the current organization.');
        }

        $providerName = AiProviderCatalog::normalize($providerConfig->provider_name);
        if (array_key_exists('provider_name', $data)
            && AiProviderCatalog::normalize($data['provider_name']) !== $providerName) {
            throw new InvalidArgumentException('Provider identity cannot be changed after creation.');
        }

        $credentialId = array_key_exists('credential_id', $data)
            ? self::credentialId($data['credential_id'])
            : $providerConfig->credential_id;
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

        $options = array_key_exists('options', $data)
            ? (array) $data['options']
            : (array) ($providerConfig->options ?? []);
        try {
            $options = AiProviderExecutionConfiguration::normalizeOptions($providerName, $options);
        } catch (AiProviderProbeUnsupportedException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        $executionChanged = $providerConfig->provider_name !== $providerName
            || (int) $providerConfig->credential_id !== (int) $credentialId
            || $this->encoded($providerConfig->options ?? []) !== $this->encoded($options);

        $providerConfig->update([
            'provider_name' => $providerName,
            'display_name' => trim((string) ($data['display_name'] ?? $providerConfig->display_name)),
            'is_enabled' => array_key_exists('is_enabled', $data) ? (bool) $data['is_enabled'] : $providerConfig->is_enabled,
            'credential_id' => $credentialId,
            'options' => $options,
            ...($executionChanged ? [
                'health_status' => ProviderHealthStatus::Unknown,
                'tested_credential_revision' => null,
                'tested_configuration_digest' => null,
                'last_checked_at' => null,
                'last_health_error' => 'Provider execution configuration changed; connection verification is required.',
            ] : []),
        ]);

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'ai.provider_config.updated',
            targetType: AiProviderConfiguration::class,
            targetId: (string) $providerConfig->getKey(),
            metadata: [
                'provider_name' => $providerName,
                'is_enabled' => $providerConfig->is_enabled,
                'credential_reassigned' => $executionChanged,
            ],
        );

        return $providerConfig->refresh();
    }

    /** @param array<string, mixed> $value */
    private function encoded(array $value): string
    {
        ksort($value);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
