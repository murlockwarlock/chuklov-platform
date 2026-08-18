<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\PromptBundle;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use InvalidArgumentException;

class ExportPromptBundle
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, int $promptVersionId): PromptBundle
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

        $prompt = $version->prompt;
        if ($prompt === null) {
            throw new InvalidArgumentException('Prompt version parent prompt not found.');
        }

        return new PromptBundle(
            promptKey: $prompt->key,
            name: $prompt->name,
            description: $prompt->description,
            capability: $prompt->capability,
            version: $version->version,
            systemPrompt: $version->system_prompt,
            userPromptTemplate: $version->user_prompt_template,
            variablesSchema: $version->variables_schema ?? [],
            parameterConfig: $version->parameter_config ?? [],
            contextPolicy: $version->context_policy ?? [],
            outputSchema: $version->output_schema,
            allowedTools: $version->allowed_tools ?? [],
            changeNotes: $version->change_notes,
        );
    }
}
