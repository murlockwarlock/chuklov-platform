<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Exceptions\AiProviderProbeException;
use App\Modules\AI\Domain\Exceptions\AiProviderProbeUnsupportedException;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Services\AiErrorSanitizer;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\AI\Infrastructure\Providers\AiProviderFactory;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
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
            && (int) $credential->organization_id === (int) $organization->getKey()
            && $this->credentialHasSecret($credential);
        if (! $credentialAvailable && AiProviderExecutionConfiguration::providerRequiresSecret($providerName)) {
            $message = 'API-ключ не подключён, неактивен или не привязан к этому провайдеру.';
            $providerConfig->update([
                'health_status' => ProviderHealthStatus::Unavailable,
                'last_checked_at' => Carbon::now(),
                'last_health_error' => $message,
                'tested_credential_revision' => null,
                'tested_configuration_digest' => null,
            ]);

            return [
                'success' => false,
                'message' => $message,
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
        } catch (AiProviderProbeUnsupportedException $exception) {
            $message = $this->unsupportedProbeMessage($exception);
            $providerConfig->update([
                'health_status' => ProviderHealthStatus::Unknown,
                'last_checked_at' => Carbon::now(),
                'last_health_error' => $message,
                'tested_credential_revision' => null,
                'tested_configuration_digest' => null,
            ]);

            return [
                'success' => false,
                'message' => $message,
            ];
        } catch (AiProviderProbeException $exception) {
            $message = $this->probeFailureMessage($exception);
            $providerConfig->update([
                'health_status' => ProviderHealthStatus::Degraded,
                'last_checked_at' => Carbon::now(),
                'last_health_error' => $message,
                'tested_credential_revision' => null,
                'tested_configuration_digest' => null,
            ]);

            return [
                'success' => false,
                'message' => $message,
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

    private function unsupportedProbeMessage(AiProviderProbeUnsupportedException $exception): string
    {
        $rawMessage = mb_strtolower($exception->getMessage());

        if (str_contains($rawMessage, 'endpoint is required')) {
            return 'Не указан endpoint провайдера. Заполните адрес подключения и повторите проверку.';
        }

        if (str_contains($rawMessage, 'api version') || str_contains($rawMessage, 'deployment')) {
            return 'Параметры Azure некорректны. Проверьте версию API и имя deployment.';
        }

        if (str_contains($rawMessage, 'endpoint') || str_contains($rawMessage, 'execution options')) {
            return 'Параметры подключения провайдера некорректны. Проверьте endpoint и настройки запроса.';
        }

        return 'Безопасная проверка связи для этого провайдера не поддерживается.';
    }

    private function credentialHasSecret(?OrganizationCredential $credential): bool
    {
        if ($credential === null) {
            return false;
        }

        $credentials = $credential->credentials ?? [];
        $secret = $credentials['api_key'] ?? $credentials['key'] ?? $credentials['secret'] ?? null;

        return is_string($secret) && trim($secret) !== '';
    }

    private function probeFailureMessage(AiProviderProbeException $exception): string
    {
        if ($exception->category === AiErrorCategory::AuthenticationFailed) {
            return $this->statusMessage(
                $exception->status,
                'API-ключ отклонён провайдером. Проверьте ключ и его права',
            );
        }

        if ($exception->category === AiErrorCategory::RateLimited) {
            return $this->statusMessage(
                $exception->status,
                'Провайдер временно ограничил запросы. Повторите проверку позже',
            );
        }

        if ($exception->category === AiErrorCategory::ExecutionTimedOut) {
            return 'Провайдер не ответил за 5 секунд. Проверьте доступность сети и endpoint.';
        }

        if ($exception->category === AiErrorCategory::ProviderUnavailable) {
            if ($exception->status !== null && $exception->status >= 500) {
                return $this->statusMessage(
                    $exception->status,
                    'Провайдер вернул серверную ошибку. Повторите проверку позже или проверьте его статус',
                );
            }

            return 'Не удалось подключиться к провайдеру. Проверьте доступность сети и endpoint.';
        }

        if ($exception->status !== null) {
            return $this->statusMessage(
                $exception->status,
                'Провайдер отклонил запрос. Проверьте endpoint и версию API',
            );
        }

        return 'Проверка связи завершилась внутренней ошибкой. Проверьте настройки провайдера.';
    }

    private function statusMessage(?int $status, string $message): string
    {
        return $status === null ? $message.'.' : $message.' (HTTP '.$status.').';
    }
}
