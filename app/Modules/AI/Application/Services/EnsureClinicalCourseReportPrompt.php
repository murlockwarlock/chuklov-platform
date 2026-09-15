<?php

namespace App\Modules\AI\Application\Services;

use App\Modules\AI\Application\Data\PromptBundle;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;

final readonly class EnsureClinicalCourseReportPrompt
{
    private const PROMPT_KEY = 'clinical_course_report';

    public function __construct(
        private OrganizationContext $context,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(): AiPromptVersion
    {
        $organization = $this->context->organization();
        $bundle = $this->bundle();

        return DB::transaction(function () use ($bundle, $organization): AiPromptVersion {
            $prompt = AiPrompt::query()
                ->where('organization_id', $organization->getKey())
                ->where('key', self::PROMPT_KEY)
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
                    ->where('key', self::PROMPT_KEY)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $created === 1) {
                    $version = new AiPromptVersion([
                        'organization_id' => $organization->getKey(),
                        'prompt_id' => $prompt->getKey(),
                        'version' => $bundle->version,
                        'status' => PromptVersionStatus::Active,
                        'system_prompt' => $bundle->systemPrompt,
                        'user_prompt_template' => $bundle->userPromptTemplate,
                        'variables_schema' => $bundle->variablesSchema,
                        'parameter_config' => $bundle->parameterConfig,
                        'context_policy' => $bundle->contextPolicy,
                        'output_schema' => $bundle->outputSchema,
                        'allowed_tools' => $bundle->allowedTools,
                        'change_notes' => $bundle->changeNotes,
                        'activated_at' => now(),
                    ]);
                    $version->save();
                    $prompt->update(['active_version_id' => $version->getKey()]);

                    $this->audit->handle(
                        organization: $organization,
                        actor: null,
                        action: 'ai.prompt.created',
                        targetType: AiPrompt::class,
                        targetId: (string) $prompt->getKey(),
                        metadata: [
                            'prompt_key' => $prompt->key,
                            'capability' => $prompt->capability->value,
                        ],
                    );
                    $this->audit->handle(
                        organization: $organization,
                        actor: null,
                        action: 'ai.prompt_version.created',
                        targetType: AiPromptVersion::class,
                        targetId: (string) $version->getKey(),
                        metadata: [
                            'prompt_key' => $prompt->key,
                            'version' => (string) $version->version,
                        ],
                    );
                    $this->audit->handle(
                        organization: $organization,
                        actor: null,
                        action: 'ai.prompt_version.activated',
                        targetType: AiPromptVersion::class,
                        targetId: (string) $version->getKey(),
                        metadata: [
                            'prompt_key' => $prompt->key,
                            'version' => (string) $version->version,
                        ],
                    );

                    return $version->refresh();
                }
            }

            if ($prompt->capability !== AiCapability::ClinicalSynthesizer) {
                throw new InvalidArgumentException('Промпт итогового отчёта курса настроен с несовместимым типом анализа.');
            }

            if ($prompt->active_version_id === null) {
                throw new InvalidArgumentException('Для итогового отчёта курса нет активной версии промпта. Обратитесь к администратору AI.');
            }

            $version = AiPromptVersion::query()
                ->where('organization_id', $organization->getKey())
                ->where('prompt_id', $prompt->getKey())
                ->whereKey($prompt->active_version_id)
                ->first();

            if ($version === null || $version->status !== PromptVersionStatus::Active) {
                throw new InvalidArgumentException('Активная версия промпта итогового отчёта курса недоступна. Обратитесь к администратору AI.');
            }

            return $version;
        });
    }

    private function bundle(): PromptBundle
    {
        $path = base_path('docs/product/source-pack/ai-prompt-bundles/clinical-course-report.json');
        if (! File::exists($path)) {
            throw new InvalidArgumentException('Промпт итогового отчёта курса недоступен. Обратитесь к администратору AI.');
        }

        try {
            $data = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Промпт итогового отчёта курса имеет недопустимый формат. Обратитесь к администратору AI.');
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Промпт итогового отчёта курса имеет недопустимый формат. Обратитесь к администратору AI.');
        }

        $bundle = PromptBundle::fromArray($data);
        if ($bundle->promptKey !== self::PROMPT_KEY || $bundle->capability !== AiCapability::ClinicalSynthesizer) {
            throw new InvalidArgumentException('Промпт итогового отчёта курса настроен с несовместимым типом анализа.');
        }

        return $bundle;
    }
}
