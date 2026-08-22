<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\ValueObjects\AiParameterConfig;
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

        return DB::transaction(function () use ($organization, $actor, $prompt, $data, $systemPrompt, $userPromptTemplate): AiPromptVersion {
            $prompt = AiPrompt::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($prompt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $latestVersion = AiPromptVersion::query()
                ->where('organization_id', $organization->getKey())
                ->where('prompt_id', $prompt->id)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            $versionNumber = ((int) ($latestVersion->version ?? 0)) + 1;

            $version = new AiPromptVersion([
                'organization_id' => $organization->getKey(),
                'prompt_id' => $prompt->id,
                'version' => $versionNumber,
                'status' => PromptVersionStatus::Draft,
                'system_prompt' => $systemPrompt,
                'user_prompt_template' => $userPromptTemplate,
                'variables_schema' => array_key_exists('variables_schema', $data)
                    ? (array) $data['variables_schema']
                    : (array) ($latestVersion->variables_schema ?? []),
                'parameter_config' => self::parameterConfig($data, $latestVersion),
                'context_policy' => array_key_exists('context_policy', $data)
                    ? (array) $data['context_policy']
                    : (array) ($latestVersion->context_policy ?? []),
                'output_schema' => array_key_exists('output_schema', $data)
                    ? ($data['output_schema'] === null ? null : (array) $data['output_schema'])
                    : ($latestVersion instanceof AiPromptVersion ? $latestVersion->output_schema : null),
                'allowed_tools' => array_key_exists('allowed_tools', $data)
                    ? array_values(array_map('strval', (array) $data['allowed_tools']))
                    : array_map('strval', (array) ($latestVersion->allowed_tools ?? [])),
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function parameterConfig(array $data, ?AiPromptVersion $latestVersion): array
    {
        $parameterConfig = (array) ($latestVersion->parameter_config ?? []);
        if (array_key_exists('parameter_config', $data)) {
            $parameterConfig = [
                ...$parameterConfig,
                ...(array) $data['parameter_config'],
            ];
        }

        foreach ([
            'temperature',
            'top_p',
            'max_tokens',
            'frequency_penalty',
            'presence_penalty',
            'timeout_seconds',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $parameterConfig[$key] = $data[$key];
            }
        }

        return array_replace(
            $parameterConfig,
            AiParameterConfig::fromArray($parameterConfig)->toArray(),
        );
    }
}
