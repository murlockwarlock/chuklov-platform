<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\PromptBundle;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;

final class MaterializeSourceBackedEvaluations
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly ImportPromptBundle $importPromptBundle,
        private readonly CreateAiEvaluationSuite $createSuite,
        private readonly CreateEvalCase $createCase,
        private readonly ActivatePromptVersion $activatePromptVersion,
    ) {}

    /** @return array{prompts_created: int, prompt_versions_created: int, prompts_activated: int, suites_created: int, cases_created: int} */
    public function handle(User $actor, bool $activatePromptVersions = false): array
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiPrompts);
        $manifest = $this->readManifest();
        $summary = $this->newSummary();

        foreach ($manifest['suites'] as $suiteData) {
            $bundle = $this->readBundle($suiteData['prompt_bundle'] ?? null);
            $prompt = AiPrompt::query()
                ->where('organization_id', $organization->getKey())
                ->where('key', $bundle->promptKey)
                ->first();

            if ($prompt !== null && $prompt->capability !== $bundle->capability) {
                throw new InvalidArgumentException('Source-backed prompt capability does not match the existing prompt.');
            }

            $promptWasCreated = $prompt === null;
            $version = $this->findMatchingPromptVersion($prompt, $bundle);
            if ($version === null) {
                $version = $this->importPromptBundle->handle($actor, $bundle);
            }
            $prompt = $version->prompt;
            if (! $prompt instanceof AiPrompt) {
                throw new InvalidArgumentException('Source-backed prompt was not materialized.');
            }

            if ($promptWasCreated) {
                $summary['prompts_created']++;
            }
            if ($version->wasRecentlyCreated) {
                $summary['prompt_versions_created']++;
            }
            if ($activatePromptVersions && $version->status !== PromptVersionStatus::Active) {
                $version = $this->activatePromptVersion->handle($actor, $version->getKey());
                $summary['prompts_activated']++;
            }

            $suite = $this->findOrCreateSuite($actor, $suiteData, $bundle->capability, $prompt->getKey(), $summary);
            $cases = $this->normalizeObjectList($suiteData['cases'] ?? [], 'Source-backed evaluation cases are invalid.');
            $this->materializeCases($actor, $suite, $cases, $suiteData['expected_output_schema'] ?? null, $summary);
        }

        return $summary;
    }

    /** @return array{schema_version: int, suites: list<array<string, mixed>>} */
    private function readManifest(): array
    {
        $path = base_path('evals/source-backed/source-backed-agent-suites.json');
        if (! File::exists($path)) {
            throw new InvalidArgumentException('Source-backed evaluation manifest is unavailable.');
        }

        try {
            $manifest = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Source-backed evaluation manifest is invalid.');
        }

        if (! is_array($manifest) || ! is_int($manifest['schema_version'] ?? null) || ! is_array($manifest['suites'] ?? null)) {
            throw new InvalidArgumentException('Source-backed evaluation manifest has an invalid structure.');
        }

        return [
            'schema_version' => $manifest['schema_version'],
            'suites' => $this->normalizeObjectList($manifest['suites'], 'Source-backed evaluation suites are invalid.'),
        ];
    }

    private function readBundle(mixed $relativePath): PromptBundle
    {
        if (! is_string($relativePath) || $relativePath === '' || str_contains($relativePath, '..')) {
            throw new InvalidArgumentException('Source-backed prompt bundle path is invalid.');
        }

        $path = base_path($relativePath);
        if (! File::exists($path)) {
            throw new InvalidArgumentException('Source-backed prompt bundle is unavailable.');
        }

        try {
            $data = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Source-backed prompt bundle is invalid.');
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Source-backed prompt bundle has an invalid structure.');
        }

        return PromptBundle::fromArray($data);
    }

    private function findMatchingPromptVersion(?AiPrompt $prompt, PromptBundle $bundle): ?AiPromptVersion
    {
        if (! $prompt instanceof AiPrompt) {
            return null;
        }

        $fingerprint = self::promptFingerprint($bundle);

        return $prompt->versions()
            ->get()
            ->first(fn (AiPromptVersion $version): bool => self::promptVersionFingerprint($version) === $fingerprint);
    }

    /**
     * @param  array<string, mixed>  $suiteData
     * @param  array{prompts_created: int, prompt_versions_created: int, prompts_activated: int, suites_created: int, cases_created: int}  $summary
     */
    private function findOrCreateSuite(User $actor, array $suiteData, AiCapability $capability, int $promptId, array &$summary): AiEvalSuite
    {
        $organization = $this->context->organization();
        $key = (string) ($suiteData['key'] ?? '');
        $suite = AiEvalSuite::query()
            ->where('organization_id', $organization->getKey())
            ->where('key', $key)
            ->first();

        if ($suite instanceof AiEvalSuite) {
            if ($suite->capability !== $capability || (int) $suite->prompt_id !== $promptId) {
                throw new InvalidArgumentException('An existing evaluation suite uses the source-backed key with a different configuration.');
            }

            $suite->update([
                'name' => (string) ($suiteData['name'] ?? $key),
                'description' => 'Демонстрационные тесты Чуклова · Синтетические данные',
            ]);

            return $suite;
        }

        $suite = $this->createSuite->handle($actor, [
            'key' => $key,
            'name' => (string) ($suiteData['name'] ?? $key),
            'description' => 'Демонстрационные тесты Чуклова · Синтетические данные',
            'capability' => $capability->value,
            'prompt_id' => $promptId,
        ]);
        $summary['suites_created']++;

        return $suite;
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @param  array{prompts_created: int, prompt_versions_created: int, prompts_activated: int, suites_created: int, cases_created: int}  $summary
     */
    private function materializeCases(User $actor, AiEvalSuite $suite, array $cases, mixed $outputSchema, array &$summary): void
    {
        if (! is_array($outputSchema)) {
            throw new InvalidArgumentException('Source-backed evaluation output schema is invalid.');
        }

        foreach ($cases as $caseData) {
            $sourceKey = (string) ($caseData['key'] ?? '');
            if ($sourceKey === '') {
                throw new InvalidArgumentException('Source-backed evaluation case key is required.');
            }

            $existing = AiEvalCase::query()
                ->where('organization_id', $suite->organization_id)
                ->where('eval_suite_id', $suite->getKey())
                ->where('source_key', $sourceKey)
                ->first();
            if ($existing instanceof AiEvalCase) {
                $existing->update([
                    'name' => (string) ($caseData['name'] ?? $sourceKey),
                    'test_inputs' => is_array($caseData['test_inputs'] ?? null) ? $caseData['test_inputs'] : [],
                    'expected_assertions' => is_array($caseData['expected_assertions'] ?? null) ? $caseData['expected_assertions'] : [],
                    'expected_output_schema' => $outputSchema,
                    'is_synthetic' => (bool) ($caseData['is_synthetic'] ?? false),
                    'is_deidentified' => (bool) ($caseData['is_deidentified'] ?? false),
                ]);

                continue;
            }

            $this->createCase->execute(
                actor: $actor,
                organization: $suite->organization,
                suiteId: $suite->getKey(),
                name: (string) ($caseData['name'] ?? $sourceKey),
                testInputs: is_array($caseData['test_inputs'] ?? null) ? $caseData['test_inputs'] : [],
                expectedAssertions: is_array($caseData['expected_assertions'] ?? null) ? $caseData['expected_assertions'] : [],
                expectedOutputSchema: $outputSchema,
                isSynthetic: (bool) ($caseData['is_synthetic'] ?? false),
                isDeidentified: (bool) ($caseData['is_deidentified'] ?? false),
                sourceKey: $sourceKey,
            );
            $summary['cases_created']++;
        }
    }

    /** @return array{prompts_created: int, prompt_versions_created: int, prompts_activated: int, suites_created: int, cases_created: int} */
    private function newSummary(): array
    {
        return [
            'prompts_created' => 0,
            'prompt_versions_created' => 0,
            'prompts_activated' => 0,
            'suites_created' => 0,
            'cases_created' => 0,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function normalizeObjectList(mixed $value, string $message): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException($message);
        }

        $normalized = [];
        foreach ($value as $item) {
            $normalized[] = $this->normalizeObject($item, $message);
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalizeObject(mixed $value, string $message): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException($message);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException($message);
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }

    private static function promptFingerprint(PromptBundle $bundle): string
    {
        return hash('sha256', json_encode([
            'system_prompt' => $bundle->systemPrompt,
            'user_prompt_template' => $bundle->userPromptTemplate,
            'variables_schema' => $bundle->variablesSchema,
            'parameter_config' => $bundle->parameterConfig,
            'context_policy' => $bundle->contextPolicy,
            'output_schema' => $bundle->outputSchema,
            'allowed_tools' => $bundle->allowedTools,
            'change_notes' => $bundle->changeNotes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function promptVersionFingerprint(AiPromptVersion $version): string
    {
        return hash('sha256', json_encode([
            'system_prompt' => $version->system_prompt,
            'user_prompt_template' => $version->user_prompt_template,
            'variables_schema' => $version->variables_schema,
            'parameter_config' => $version->parameter_config,
            'context_policy' => $version->context_policy,
            'output_schema' => $version->output_schema,
            'allowed_tools' => $version->allowed_tools,
            'change_notes' => $version->change_notes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
