<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\ValueObjects\AiParameterConfig;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SavePromptDraft
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, int $promptVersionId, array $data): AiPromptVersion
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiPrompts);

        $systemPrompt = trim((string) ($data['system_prompt'] ?? ''));
        $userPromptTemplate = trim((string) ($data['user_prompt_template'] ?? ''));
        if ($systemPrompt === '' || $userPromptTemplate === '') {
            throw new InvalidArgumentException('Prompt text and request template are required.');
        }

        return DB::transaction(function () use ($organization, $actor, $promptVersionId, $data, $systemPrompt, $userPromptTemplate): AiPromptVersion {
            $version = AiPromptVersion::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($promptVersionId)
                ->lockForUpdate()
                ->first();

            if (! $version instanceof AiPromptVersion || $version->status !== PromptVersionStatus::Draft) {
                throw new InvalidArgumentException('Only a draft prompt version can be edited.');
            }

            $parameterConfig = [
                ...(array) $version->parameter_config,
                ...array_intersect_key($data, array_flip([
                    'temperature',
                    'top_p',
                    'max_tokens',
                    'frequency_penalty',
                    'presence_penalty',
                    'timeout_seconds',
                ])),
            ];

            $version->update([
                'system_prompt' => $systemPrompt,
                'user_prompt_template' => $userPromptTemplate,
                'parameter_config' => AiParameterConfig::fromArray($parameterConfig)->toArray(),
                'change_notes' => isset($data['change_notes']) ? trim((string) $data['change_notes']) : null,
            ]);

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'ai.prompt_version.updated',
                targetType: AiPromptVersion::class,
                targetId: (string) $version->getKey(),
                metadata: [
                    'prompt_id' => (string) $version->prompt_id,
                    'version' => (string) $version->version,
                ],
            );

            return $version->refresh();
        });
    }
}
