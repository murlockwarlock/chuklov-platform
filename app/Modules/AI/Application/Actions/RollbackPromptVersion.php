<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use InvalidArgumentException;

class RollbackPromptVersion
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly ActivatePromptVersion $activatePromptVersion,
    ) {}

    public function handle(User $actor, int $promptId, int $targetVersionNumber): AiPromptVersion
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ActivateAiReleases);

        $prompt = AiPrompt::query()
            ->where('organization_id', $organization->getKey())
            ->where('id', $promptId)
            ->first();

        if ($prompt === null) {
            throw new InvalidArgumentException('Prompt not found.');
        }

        $targetVersion = AiPromptVersion::query()
            ->where('organization_id', $organization->getKey())
            ->where('prompt_id', $prompt->id)
            ->where('version', $targetVersionNumber)
            ->first();

        if ($targetVersion === null) {
            throw new InvalidArgumentException("Version {$targetVersionNumber} not found for this prompt.");
        }

        return $this->activatePromptVersion->handle($actor, $targetVersion->id);
    }
}
