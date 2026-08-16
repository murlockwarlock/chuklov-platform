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
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreatePromptDraft
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, int $promptId, array $data): AiPromptVersion
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiPrompts);

        $prompt = AiPrompt::query()
            ->where('organization_id', $organization->getKey())
            ->where('id', $promptId)
            ->first();

        if ($prompt === null) {
            throw new InvalidArgumentException('Prompt not found.');
        }

        $systemPrompt = trim((string) ($data['system_prompt'] ?? ''));
        $userPromptTemplate = trim((string) ($data['user_prompt_template'] ?? ''));

        if ($systemPrompt === '') {
            throw new InvalidArgumentException('System prompt cannot be empty.');
        }

        if ($userPromptTemplate === '') {
            throw new InvalidArgumentException('User prompt template cannot be empty.');
        }

        return DB::transaction(function () use ($organization, $actor, $prompt, $data, $systemPrompt, $userPromptTemplate) {
            $latestVersion = AiPromptVersion::query()
                ->where('organization_id', $organization->getKey())
                ->where('prompt_id', $prompt->id)
                ->max('version') ?? 0;

            $versionNumber = $latestVersion + 1;

            $version = new AiPromptVersion([
                'organization_id' => $organization->getKey(),
                'prompt_id' => $prompt->id,
                'version' => $versionNumber,
                'status' => PromptVersionStatus::Draft,
                'system_prompt' => $systemPrompt,
                'user_prompt_template' => $userPromptTemplate,
                'variables_schema' => (array) ($data['variables_schema'] ?? []),
                'parameter_config' => (array) ($data['parameter_config'] ?? []),
                'context_policy' => (array) ($data['context_policy'] ?? []),
                'output_schema' => isset($data['output_schema']) ? (array) $data['output_schema'] : null,
                'allowed_tools' => array_values(array_map('strval', (array) ($data['allowed_tools'] ?? []))),
                'change_notes' => isset($data['change_notes']) ? (string) $data['change_notes'] : null,
            ]);
            $version->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'ai.prompt_version.created',
                targetType: AiPromptVersion::class,
                targetId: (string) $version->id,
                metadata: [
                    'prompt_key' => $prompt->key,
                    'version' => (string) $versionNumber,
                ],
            );

            return $version;
        });
    }
}
