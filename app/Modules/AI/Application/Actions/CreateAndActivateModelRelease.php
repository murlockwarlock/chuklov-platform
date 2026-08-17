<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class CreateAndActivateModelRelease
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, AiModelConfiguration $modelConfig, array $data): AiModelRelease
    {
        $organization = $modelConfig->organization;
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ActivateAiReleases);

        $providerManagementKeys = [
            'model_name',
            'display_name',
            'capabilities',
            'pricing_snapshot',
            'input_cost_per_million',
            'output_cost_per_million',
            'failover_priority',
            'is_enabled',
        ];
        if (array_intersect($providerManagementKeys, array_keys($data)) !== []) {
            $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiProviders);
        }

        return DB::transaction(function () use ($organization, $actor, $modelConfig, $data, $providerManagementKeys): AiModelRelease {
            $lockedConfig = AiModelConfiguration::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($modelConfig->getKey())
                ->with('providerConfiguration')
                ->lockForUpdate()
                ->first();

            if ($lockedConfig === null) {
                throw new AuthorizationException('Model configuration is outside the current organization.');
            }

            $providerConfig = $lockedConfig->providerConfiguration;
            if ($providerConfig === null || (int) $providerConfig->organization_id !== (int) $organization->getKey()) {
                throw new AuthorizationException('Model provider configuration is outside the current organization.');
            }

            $pricing = isset($data['pricing_snapshot']) && is_array($data['pricing_snapshot'])
                ? $data['pricing_snapshot']
                : (array) $lockedConfig->pricing_snapshot;
            if (array_key_exists('input_cost_per_million', $data) || array_key_exists('output_cost_per_million', $data)) {
                $pricing = (new AiPricingSnapshot(
                    currency: 'USD',
                    inputCostPerMillionMinorUnits: max(0, (int) ($data['input_cost_per_million'] ?? 0)),
                    outputCostPerMillionMinorUnits: max(0, (int) ($data['output_cost_per_million'] ?? 0)),
                ))->toArray();
            }

            $modelName = (string) ($data['model_name'] ?? $lockedConfig->model_name);
            $capabilities = array_values(array_map('strval', (array) ($data['capabilities'] ?? $lockedConfig->capabilities ?? [])));
            $releaseNumber = ((int) AiModelRelease::query()
                ->where('organization_id', $organization->getKey())
                ->where('model_config_id', $lockedConfig->getKey())
                ->max('release_number')) + 1;

            $release = AiModelRelease::create([
                'organization_id' => $organization->getKey(),
                'model_config_id' => $lockedConfig->getKey(),
                'release_number' => $releaseNumber,
                'status' => 'active',
                'provider_name' => $providerConfig->provider_name,
                'model_name' => $modelName,
                'capabilities' => $capabilities,
                'pricing_snapshot' => $pricing,
                'activated_at' => Carbon::now(),
                'activated_by_user_id' => $actor->getKey(),
            ]);

            AiModelRelease::query()
                ->where('organization_id', $organization->getKey())
                ->where('model_config_id', $lockedConfig->getKey())
                ->where('id', '!=', $release->getKey())
                ->update(['status' => 'retired']);

            $configUpdates = [
                'active_release_id' => $release->getKey(),
                'is_enabled' => true,
                'lifecycle_status' => 'active',
            ];
            if (array_intersect($providerManagementKeys, array_keys($data)) !== []) {
                $configUpdates = [
                    ...$configUpdates,
                    'model_name' => $modelName,
                    'display_name' => (string) ($data['display_name'] ?? $lockedConfig->display_name),
                    'capabilities' => $capabilities,
                    'pricing_snapshot' => $pricing,
                    'failover_priority' => max(1, (int) ($data['failover_priority'] ?? $lockedConfig->failover_priority)),
                ];
            }
            $lockedConfig->update($configUpdates);

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'ai.model_release.activated',
                targetType: AiModelRelease::class,
                targetId: (string) $release->getKey(),
                metadata: [
                    'model_name' => $release->model_name,
                    'release_number' => (string) $release->release_number,
                ],
            );

            return $release;
        });
    }
}
