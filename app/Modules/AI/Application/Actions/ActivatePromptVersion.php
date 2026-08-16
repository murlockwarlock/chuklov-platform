<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ActivatePromptVersion
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, int $promptVersionId): AiPromptVersion
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ActivateAiReleases);

        $version = AiPromptVersion::query()
            ->where('organization_id', $organization->getKey())
            ->where('id', $promptVersionId)
            ->first();

        if ($version === null) {
            throw new InvalidArgumentException('Prompt version not found.');
        }

        return DB::transaction(function () use ($organization, $actor, $version) {
            $prompt = AiPrompt::query()
                ->where('organization_id', $organization->getKey())
                ->where('id', $version->prompt_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($prompt->active_version_id !== null && $prompt->active_version_id !== $version->id) {
                AiPromptVersion::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('id', $prompt->active_version_id)
                    ->update([
                        'status' => PromptVersionStatus::Retired,
                        'updated_at' => Carbon::now(),
                    ]);
            }

            $version->update([
                'status' => PromptVersionStatus::Active,
                'activated_at' => Carbon::now(),
                'activated_by_user_id' => $actor->getKey(),
            ]);

            $prompt->update([
                'active_version_id' => $version->id,
            ]);

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'ai.prompt_version.activated',
                targetType: AiPromptVersion::class,
                targetId: (string) $version->id,
                metadata: [
                    'prompt_key' => $prompt->key,
                    'version' => (string) $version->version,
                ],
            );

            return $version->refresh();
        });
    }
}
