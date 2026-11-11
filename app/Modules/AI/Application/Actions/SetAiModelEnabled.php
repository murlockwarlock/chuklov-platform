<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class SetAiModelEnabled
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, AiModelConfiguration $modelConfig, bool $enabled): AiModelConfiguration
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiProviders);

        if ((int) $modelConfig->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('Model configuration is outside the current organization.');
        }

        return DB::transaction(function () use ($actor, $organization, $modelConfig, $enabled): AiModelConfiguration {
            $locked = AiModelConfiguration::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($modelConfig->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $providerExists = AiProviderConfiguration::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($locked->provider_config_id)
                ->exists();
            if (! $providerExists) {
                throw new AuthorizationException('Model provider configuration is outside the current organization.');
            }

            if ((bool) $locked->is_enabled !== $enabled) {
                $locked->forceFill(['is_enabled' => $enabled])->save();
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'ai.model_config.updated',
                    targetType: AiModelConfiguration::class,
                    targetId: (string) $locked->getKey(),
                    metadata: [
                        'model_name' => $locked->model_name,
                        'is_enabled' => $enabled,
                    ],
                );
            }

            return $locked->refresh();
        });
    }
}
