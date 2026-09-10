<?php

namespace Tests\Feature\ClientCompanion;

use App\Modules\AI\Application\Data\PromptBundle;
use App\Modules\AI\Domain\Services\AiEvaluationAssertionRegistry;
use Tests\TestCase;

final class CompanionV4EvaluationTest extends TestCase
{
    public function test_companion_v4_bundle_and_exported_cases_are_executable(): void
    {
        $bundle = PromptBundle::fromArray(json_decode(
            (string) file_get_contents(base_path('docs/product/source-pack/ai-prompt-bundles/client-companion.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));

        self::assertSame(4, $bundle->version);
        self::assertSame('Companion v4 — usefulness', $bundle->name);
        self::assertArrayHasKey('health_context', $bundle->variablesSchema['properties']);
        self::assertStringContainsString('15–20 абзацах', $bundle->systemPrompt);
        self::assertStringContainsString('не надо специалиста', $bundle->systemPrompt);

        $manifest = json_decode(
            (string) file_get_contents(base_path('evals/source-backed/source-backed-agent-suites.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $suite = collect($manifest['suites'])->firstWhere('key', 'source_agent_4_client_companion');
        self::assertIsArray($suite);

        $cases = collect($suite['cases'])->keyBy('key');
        $requiredCases = [
            'negated_human_request_stays_ai',
            'named_serious_condition_is_explained',
            'requested_recovery_framework_is_detailed',
            'companion_capabilities_are_broad',
            'tracker_is_explained_without_fabricated_data',
            'explicit_specialist_request_is_handoff',
            'unavailable_image_content_is_acknowledged',
        ];
        foreach ($requiredCases as $key) {
            self::assertArrayHasKey($key, $cases->all());
        }

        $outputs = [
            'negated_human_request_stays_ai' => ['decision' => 'reply', 'reply' => 'Привет! Я сам помогу разобраться и расскажу, что умею.', 'handoff_reason' => ''],
            'named_serious_condition_is_explained' => ['decision' => 'reply', 'reply' => 'Могу объяснить этот термин и разобрать вопросы восстановления в общем образовательном формате.', 'handoff_reason' => ''],
            'requested_recovery_framework_is_detailed' => ['decision' => 'reply', 'reply' => collect(range(1, 15))->map(static fn (int $paragraph): string => "Абзац {$paragraph}: общий образовательный framework восстановления отделяет наблюдение от индивидуальных назначений и учитывает постепенность нагрузки.")->implode("\n\n"), 'handoff_reason' => ''],
            'companion_capabilities_are_broad' => ['decision' => 'reply', 'reply' => 'Я могу объяснять здоровье, тесты, результаты, Road Map и Трекер, помогать с навигацией и подготовить вопросы специалисту.', 'handoff_reason' => ''],
            'tracker_is_explained_without_fabricated_data' => ['decision' => 'reply', 'reply' => 'Трекер помогает отмечать задачи и самочувствие в динамике. Конкретные цифры зависят от ваших реальных отметок.', 'handoff_reason' => ''],
            'explicit_specialist_request_is_handoff' => ['decision' => 'handoff_required', 'reply' => 'Понял. Передаю запрос специалисту.', 'handoff_reason' => 'human_requested'],
            'unavailable_image_content_is_acknowledged' => ['decision' => 'reply', 'reply' => 'Я не вижу конкретных значений на изображении, поэтому не буду придумывать результат. Можно перепечатать значения из бланка.', 'handoff_reason' => ''],
        ];

        foreach ($requiredCases as $key) {
            $case = $cases->get($key);
            self::assertIsArray($case);
            $results = app(AiEvaluationAssertionRegistry::class)->evaluate(
                definitions: $case['expected_assertions'],
                expectedSchema: null,
                outputText: $outputs[$key]['reply'],
                outputPayload: $outputs[$key],
                ragReferences: [],
            );
            foreach ($results as $result) {
                self::assertTrue($result->passed, $key.': '.$result->explanation);
            }
        }
    }
}
