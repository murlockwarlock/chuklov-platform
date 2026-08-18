<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\PromptBundle;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ImportPromptBundle
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, PromptBundle|string $bundle): AiPromptVersion
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiPrompts);

        if (is_string($bundle)) {
            $decoded = json_decode($bundle, true);
            if (! is_array($decoded)) {
                throw new InvalidArgumentException('Invalid JSON provided for prompt bundle.');
            }
            $bundle = PromptBundle::fromArray($decoded);
        }

        return DB::transaction(function () use ($organization, $actor, $bundle): AiPromptVersion {
            $prompt = AiPrompt::query()
                ->where('organization_id', $organization->getKey())
                ->where('key', $bundle->promptKey)
                ->lockForUpdate()
                ->first();

            if ($prompt === null) {
                $created = AiPrompt::query()->insertOrIgnore([
                    'organization_id' => $organization->getKey(),
                    'key' => $bundle->promptKey,
                    'name' => $bundle->name,
                    'description' => $bundle->description,
                    'capability' => $bundle->capability->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $prompt = AiPrompt::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('key', $bundle->promptKey)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $created === 1) {
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: 'ai.prompt.created',
                        targetType: AiPrompt::class,
                        targetId: (string) $prompt->id,
                        metadata: [
                            'prompt_key' => $prompt->key,
                            'capability' => $prompt->capability->value,
                        ],
                    );
                }
            }

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
                'system_prompt' => $bundle->systemPrompt,
                'user_prompt_template' => $bundle->userPromptTemplate,
                'variables_schema' => $bundle->variablesSchema,
                'parameter_config' => $bundle->parameterConfig,
                'context_policy' => $bundle->contextPolicy,
                'output_schema' => $bundle->outputSchema,
                'allowed_tools' => $bundle->allowedTools,
                'change_notes' => $bundle->changeNotes ?? 'Imported from bundle',
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
