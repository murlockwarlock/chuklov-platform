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
use Illuminate\Support\Facades\DB;

class CreateAndActivateModelRelease
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, AiModelConfiguration $modelConfig, array $data): AiModelRelease
    {
        $organization = $modelConfig->organization;
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ActivateAiReleases);

        return DB::transaction(function () use ($organization, $actor, $modelConfig, $data) {
            $latestNumber = AiModelRelease::query()
                ->where('organization_id', $organization->id)
                ->where('model_config_id', $modelConfig->id)
                ->max('release_number') ?? 0;

            $releaseNumber = $latestNumber + 1;

            $pricing = isset($data['pricing_snapshot']) && is_array($data['pricing_snapshot'])
                ? $data['pricing_snapshot']
                : (new AiPricingSnapshot(
                    currency: 'USD',
                    inputCostPerMillionMinorUnits: (int) ($data['input_cost_per_million'] ?? 15),
                    outputCostPerMillionMinorUnits: (int) ($data['output_cost_per_million'] ?? 60),
                ))->toArray();

            $modelName = (string) ($data['model_name'] ?? $modelConfig->model_name);
            $capabilities = array_values(array_map('strval', (array) ($data['capabilities'] ?? $modelConfig->capabilities ?? [])));

            $providerConfig = $modelConfig->providerConfiguration;
            if ($providerConfig === null) {
                throw new \InvalidArgumentException('Model configuration is missing an associated provider configuration.');
            }

            $release = new AiModelRelease([
                'organization_id' => $organization->id,
                'model_config_id' => $modelConfig->id,
                'release_number' => $releaseNumber,
                'status' => 'active',
                'provider_name' => $providerConfig->provider_name,
                'model_name' => $modelName,
                'capabilities' => $capabilities,
                'pricing_snapshot' => $pricing,
                'activated_at' => Carbon::now(),
                'activated_by_user_id' => $actor->id,
            ]);
            $release->save();

            AiModelRelease::query()
                ->where('organization_id', $organization->id)
                ->where('model_config_id', $modelConfig->id)
                ->where('id', '!=', $release->id)
                ->update(['status' => 'retired']);

            $modelConfig->update([
                'active_release_id' => $release->id,
                'model_name' => $modelName,
                'display_name' => (string) ($data['display_name'] ?? $modelConfig->display_name),
                'capabilities' => $capabilities,
                'pricing_snapshot' => $pricing,
                'is_enabled' => (bool) ($data['is_enabled'] ?? $modelConfig->is_enabled),
                'failover_priority' => (int) ($data['failover_priority'] ?? $modelConfig->failover_priority),
            ]);

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'ai.model_release.activated',
                targetType: AiModelRelease::class,
                targetId: (string) $release->id,
                metadata: [
                    'model_config_id' => (string) $modelConfig->id,
                    'release_number' => (string) $releaseNumber,
                    'model_name' => $modelName,
                ],
            );

            return $release;
        });
    }
}
