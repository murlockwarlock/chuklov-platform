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

class RetirePromptVersion
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, int $promptVersionId): AiPromptVersion
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiPrompts);

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

            if ($prompt->active_version_id === $version->id) {
                $prompt->update(['active_version_id' => null]);
            }

            $version->update([
                'status' => PromptVersionStatus::Retired,
                'updated_at' => Carbon::now(),
            ]);

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'ai.prompt_version.retired',
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
