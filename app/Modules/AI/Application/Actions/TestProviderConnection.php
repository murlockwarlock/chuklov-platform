<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Exceptions\AiProviderProbeUnsupportedException;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Services\AiErrorSanitizer;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\AI\Infrastructure\Providers\AiProviderFactory;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use Carbon\Carbon;

class TestProviderConnection
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly AiProviderFactory $providerFactory,
    ) {}

    /**
     * @return array{success: bool, message: string}
     */
    public function handle(User $actor, int $providerConfigId): array
    {
        $providerConfig = AiProviderConfiguration::query()
            ->with(['organization', 'credential'])
            ->where('organization_id', $this->context->id())
            ->whereKey($providerConfigId)
            ->firstOrFail();

        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiProviders);

        $credential = $providerConfig->credential;
        $providerName = $providerConfig->provider_name;
        $credentialAvailable = $credential !== null
            && $credential->status === CredentialStatus::Active
            && $credential->provider === $providerName
            && (int) $credential->organization_id === (int) $organization->getKey();
        if (! $credentialAvailable && AiProviderExecutionConfiguration::providerRequiresSecret($providerName)) {
            $providerConfig->update([
                'health_status' => ProviderHealthStatus::Unavailable,
                'last_checked_at' => Carbon::now(),
                'last_health_error' => 'No active organization credentials attached.',
                'tested_credential_revision' => null,
                'tested_configuration_digest' => null,
            ]);

            return [
                'success' => false,
                'message' => 'No active organization credentials attached.',
            ];
        }

        try {
            $this->providerFactory->testConnectivity(
                $providerName,
                $credentialAvailable ? $credential : null,
                $providerConfig->options ?? [],
            );

            $configurationDigest = AiProviderExecutionConfiguration::digest(
                $providerConfig->provider_name,
                $providerConfig->options ?? [],
            );

            $providerConfig->update([
                'health_status' => ProviderHealthStatus::Healthy,
                'last_checked_at' => Carbon::now(),
                'last_health_error' => null,
                'tested_credential_revision' => $credential?->revision_id,
                'tested_configuration_digest' => $configurationDigest,
            ]);

            return [
                'success' => true,
                'message' => 'Connection to provider succeeded.',
            ];
        } catch (AiProviderProbeUnsupportedException) {
            $providerConfig->update([
                'health_status' => ProviderHealthStatus::Unknown,
                'last_checked_at' => Carbon::now(),
                'last_health_error' => 'A safe authenticated connectivity probe is not supported for this provider.',
                'tested_credential_revision' => null,
                'tested_configuration_digest' => null,
            ]);

            return [
                'success' => false,
                'message' => 'A safe authenticated connectivity probe is not supported for this provider.',
            ];
        } catch (\Throwable $e) {
            $sanitized = AiErrorSanitizer::sanitize($e);
            $providerConfig->update([
                'health_status' => ProviderHealthStatus::Degraded,
                'last_checked_at' => Carbon::now(),
                'last_health_error' => $sanitized['message'],
                'tested_credential_revision' => null,
                'tested_configuration_digest' => null,
            ]);

            return [
                'success' => false,
                'message' => $sanitized['message'],
            ];
        }
    }
}
