<?php

namespace App\Modules\AI\Application\Actions;

use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Registry\AiModelCatalog;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\AI\Infrastructure\Providers\AiProviderFactory;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

final class ResolveAiExecutionCandidates
{
    /**
     * @return list<array<string, mixed>>
     */
    public function snapshot(
        int $organizationId,
        AiRunRequest $request,
        ?AiOrganizationSafetyControl $safetyControls,
    ): array {
        $explicitRelease = $request->modelReleaseId !== null
            ? AiModelRelease::query()
                ->where('organization_id', $organizationId)
                ->whereKey($request->modelReleaseId)
                ->with(['modelConfiguration.providerConfiguration.credential'])
                ->first()
            : null;
        $modelConfigs = $this->modelConfigurations(
            organizationId: $organizationId,
            capability: $request->capability->value,
            modelReleaseId: $request->modelReleaseId,
            safetyControls: $safetyControls,
        );
        $snapshot = [];

        foreach ($modelConfigs as $config) {
            if (count($snapshot) >= AiRuntimeLimits::PLATFORM_MAX_MODEL_CONFIGURATION_SCAN) {
                break;
            }

            $release = $request->modelReleaseId !== null
                ? $explicitRelease
                : $config->activeRelease;
            $allowedStatuses = $request->modelReleaseId !== null ? ['active', 'retired'] : ['active'];
            $candidate = $this->validatedCandidate(
                config: $config,
                release: $release,
                capability: $request->capability->value,
                allowedReleaseStatuses: $allowedStatuses,
                safetyControls: $safetyControls,
            );

            if ($candidate === null) {
                continue;
            }

            if (! $this->supportsRequiredModalities($candidate, $request->requiredModalities)) {
                continue;
            }

            $snapshot[] = $this->snapshotCandidate($candidate, count($snapshot));
        }

        return $snapshot;
    }

    /**
     * Resolve only candidates recorded in the accepted snapshot. A missing or malformed
     * snapshot is deliberately treated as no executable candidate.
     *
     * @return list<array<string, mixed>>
     */
    public function resolveSnapshot(
        int $organizationId,
        AiRun $run,
        ?AiOrganizationSafetyControl $safetyControls,
    ): array {
        if ($run->execution_mode !== AiExecutionMode::Async) {
            return [];
        }

        $recorded = $run->execution_candidate_snapshot;
        if (! is_array($recorded) || $recorded === []) {
            return [];
        }

        $candidates = [];
        $recorded = array_slice(
            array_values($recorded),
            0,
            AiRuntimeLimits::PLATFORM_MAX_MODEL_CONFIGURATION_SCAN,
        );
        foreach ($recorded as $position => $record) {
            if (! is_array($record)) {
                continue;
            }

            $releaseId = (int) ($record['model_release_id'] ?? 0);
            $release = $releaseId > 0
                ? AiModelRelease::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($releaseId)
                    ->with(['modelConfiguration.providerConfiguration.credential'])
                    ->first()
                : null;

            if ($release === null) {
                continue;
            }

            $config = $release->modelConfiguration;
            $candidate = $this->validatedCandidate(
                config: $config,
                release: $release,
                capability: $run->capability->value,
                allowedReleaseStatuses: ['active', 'retired'],
                safetyControls: $safetyControls,
            );

            if ($candidate === null || ! $this->matchesSnapshot($candidate, $record)) {
                continue;
            }

            try {
                $pricing = AiPricingSnapshot::fromArray((array) ($record['pricing_snapshot'] ?? []));
            } catch (Throwable) {
                continue;
            }

            if (! $pricing->isComplete()) {
                continue;
            }

            $candidate['pricing'] = $pricing;
            $candidate['snapshot_position'] = (int) ($record['position'] ?? $position);
            $candidate['provider_configuration_id'] = (int) $record['provider_configuration_id'];
            $candidate['provider_configuration_digest'] = (string) $record['provider_configuration_digest'];
            $candidates[] = $candidate;
        }

        usort($candidates, static fn (array $left, array $right): int => $left['snapshot_position'] <=> $right['snapshot_position']);

        return $candidates;
    }

    /** @return array<string, mixed>|null */
    public function refreshSnapshotCandidate(int $organizationId, AiRun $run, int $position): ?array
    {
        $safetyControls = AiOrganizationSafetyControl::query()
            ->where('organization_id', $organizationId)
            ->first();

        foreach ($this->resolveSnapshot($organizationId, $run, $safetyControls) as $candidate) {
            if ((int) ($candidate['snapshot_position'] ?? -1) === $position) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<int, AiModelConfiguration> */
    private function modelConfigurations(
        int $organizationId,
        string $capability,
        ?int $modelReleaseId,
        ?AiOrganizationSafetyControl $safetyControls,
    ): array {
        if ($modelReleaseId !== null) {
            $release = AiModelRelease::query()
                ->where('organization_id', $organizationId)
                ->whereKey($modelReleaseId)
                ->with(['modelConfiguration.providerConfiguration.credential'])
                ->first();

            return $release?->modelConfiguration === null ? [] : [$release->modelConfiguration];
        }

        return AiModelConfiguration::query()
            ->where('organization_id', $organizationId)
            ->where('is_enabled', true)
            ->where('lifecycle_status', 'active')
            ->whereHas('activeRelease', static function (Builder $query) use ($capability): void {
                $query
                    ->where('status', 'active')
                    ->whereJsonContains('capabilities', $capability);
            })
            ->whereHas('providerConfiguration', static function (Builder $query) use ($safetyControls): void {
                $query
                    ->where('is_enabled', true)
                    ->where('health_status', ProviderHealthStatus::Healthy->value);

                if ($safetyControls !== null && $safetyControls->disabled_providers !== []) {
                    $query->whereNotIn('provider_name', $safetyControls->disabled_providers);
                }

                $query->where(function (Builder $credentialScope): void {
                    $credentialScope
                        ->whereIn('provider_name', ['ollama', 'openai_compatible'])
                        ->whereNull('tested_credential_revision')
                        ->orWhereHas('credential', static function (Builder $credentialQuery): void {
                            $credentialQuery
                                ->where('status', CredentialStatus::Active->value)
                                ->whereNotNull('revision_id')
                                ->whereColumn('organization_credentials.provider', 'ai_provider_configurations.provider_name')
                                ->whereColumn('organization_credentials.revision_id', 'ai_provider_configurations.tested_credential_revision');
                        });
                });
            })
            ->with(['providerConfiguration.credential', 'activeRelease'])
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->limit(AiRuntimeLimits::PLATFORM_MAX_MODEL_CONFIGURATION_SCAN)
            ->get()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $allowedReleaseStatuses
     * @return array<string, mixed>|null
     */
    private function validatedCandidate(
        ?AiModelConfiguration $config,
        ?AiModelRelease $release,
        string $capability,
        array $allowedReleaseStatuses,
        ?AiOrganizationSafetyControl $safetyControls,
    ): ?array {
        if ($config === null || $release === null
            || ! in_array($release->status, $allowedReleaseStatuses, true)
            || (int) $config->organization_id !== (int) $release->organization_id
            || ! $config->is_enabled
            || $config->lifecycle_status->value !== 'active'
            || ! in_array($capability, $release->capabilities, true)) {
            return null;
        }

        $providerConfig = $config->providerConfiguration;
        if ($providerConfig === null
            || (int) $providerConfig->organization_id !== (int) $config->organization_id
            || $providerConfig->provider_name !== $release->provider_name
            || ! $providerConfig->is_enabled
            || $providerConfig->health_status !== ProviderHealthStatus::Healthy) {
            return null;
        }

        if ($safetyControls !== null && ! $safetyControls->isProviderEnabled($providerConfig->provider_name)) {
            return null;
        }

        $credential = $providerConfig->credential;
        $credentiallessProvider = ! AiProviderExecutionConfiguration::providerRequiresSecret($providerConfig->provider_name);
        if ($credential === null) {
            if (! $credentiallessProvider || $providerConfig->tested_credential_revision !== null) {
                return null;
            }
        } elseif ((int) $credential->organization_id !== (int) $providerConfig->organization_id
            || $credential->provider !== $providerConfig->provider_name
            || $credential->status !== CredentialStatus::Active
            || $credential->revision_id === null
            || $providerConfig->tested_credential_revision !== $credential->revision_id) {
            return null;
        }

        try {
            $configurationDigest = AiProviderExecutionConfiguration::digest(
                $providerConfig->provider_name,
                $providerConfig->options ?? [],
            );
            $pricing = $release->getPricingSnapshot();
        } catch (Throwable) {
            return null;
        }

        if ($providerConfig->tested_configuration_digest !== $configurationDigest
            || ! $pricing->isComplete()
            || AiModelCatalog::pricingIsStale($release->provider_name, $release->model_name, $pricing)) {
            return null;
        }

        return [
            'provider' => $release->provider_name,
            'model' => $release->model_name,
            'release' => $release,
            'credential' => $credential,
            'credential_id' => $credential->id ?? 0,
            'credential_revision' => (string) ($credential->revision_id ?? 'none'),
            'provider_configuration_id' => $providerConfig->id,
            'provider_configuration_digest' => $configurationDigest,
            'provider_options' => (array) ($providerConfig->options ?? []),
            'pricing' => $pricing,
            'failover_priority' => $config->failover_priority,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function snapshotCandidate(array $candidate, int $position): array
    {
        /** @var AiModelRelease $release */
        $release = $candidate['release'];
        /** @var AiPricingSnapshot $pricing */
        $pricing = $candidate['pricing'];

        return [
            'position' => $position,
            'model_config_id' => (int) $release->model_config_id,
            'model_release_id' => (int) $release->id,
            'provider_configuration_id' => (int) $candidate['provider_configuration_id'],
            'provider_configuration_digest' => (string) $candidate['provider_configuration_digest'],
            'provider_options' => (array) ($candidate['provider_options'] ?? []),
            'provider' => (string) $candidate['provider'],
            'model' => (string) $candidate['model'],
            'capabilities' => $this->releaseCapabilities($release),
            'credential_id' => (int) $candidate['credential_id'],
            'credential_revision' => (string) $candidate['credential_revision'],
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => (int) $candidate['failover_priority'],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<AiModelModality>  $requiredModalities
     */
    private function supportsRequiredModalities(array $candidate, array $requiredModalities): bool
    {
        if ($requiredModalities === []) {
            return true;
        }

        return AiProviderFactory::supportsAttachments(
            providerName: (string) $candidate['provider'],
            release: $candidate['release'],
            requiredModalities: $requiredModalities,
        );
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $record
     */
    private function matchesSnapshot(array $candidate, array $record): bool
    {
        /** @var AiModelRelease $release */
        $release = $candidate['release'];

        return (int) ($record['model_config_id'] ?? 0) === (int) $release->model_config_id
            && (int) ($record['model_release_id'] ?? 0) === (int) $release->id
            && (int) ($record['provider_configuration_id'] ?? 0) === (int) $candidate['provider_configuration_id']
            && hash_equals((string) ($record['provider_configuration_digest'] ?? ''), (string) $candidate['provider_configuration_digest'])
            && (string) ($record['provider'] ?? '') === (string) $candidate['provider']
            && (string) ($record['model'] ?? '') === (string) $candidate['model']
            && is_array($record['capabilities'] ?? null)
            && $record['capabilities'] === $this->releaseCapabilities($release)
            && (int) ($record['credential_id'] ?? 0) === (int) $candidate['credential_id']
            && (string) ($record['credential_revision'] ?? '') === (string) $candidate['credential_revision'];
    }

    /** @return list<string> */
    private function releaseCapabilities(AiModelRelease $release): array
    {
        $capabilities = $release->getAttribute('capabilities');

        if (! is_array($capabilities)) {
            return [];
        }

        return array_values(array_filter(
            $capabilities,
            static fn (mixed $capability): bool => is_string($capability),
        ));
    }
}
