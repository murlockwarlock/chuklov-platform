<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Application\Data\ContextAssemblyResult;
use App\Modules\AI\Application\Services\BoundedAiPromptContext;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\AI\Infrastructure\Prompt\SafePromptRenderer;
use App\Modules\Knowledge\Application\Data\RetrievalResult;
use Tests\TestCase;

final class BoundedAiPromptContextTest extends TestCase
{
    public function test_rag_is_preserved_when_health_context_can_be_bounded_to_fit(): void
    {
        $ragResult = new RetrievalResult(
            chunkId: 101,
            sourceId: 5,
            sourceTitle: 'Acceptance knowledge',
            sourceType: 'authored_text',
            revisionId: 2,
            revisionVersion: 1,
            chunkIndex: 0,
            content: 'Qualifying RAG evidence.',
            similarity: 0.91,
            sourceReference: null,
            startOffset: 0,
            endOffset: 24,
            ingestionRunId: 1,
            embeddingConfigurationKey: 'test_embedding',
        );
        $history = collect(range(1, 12))
            ->map(fn (int $index): string => "[Client] Старое сообщение {$index}\n[AI] Старый ответ {$index}")
            ->implode("\n\n");
        $assembly = new ContextAssemblyResult(
            variables: [
                'conversation_history' => $history,
                'current_message' => 'Что показал мой тест?',
                'health_context' => str_repeat("Результат теста: зона внимания — сон и восстановление.\n", 120),
                'rag_context' => '[Источник: Acceptance knowledge] Qualifying RAG evidence.',
            ],
            ragChunks: [$ragResult],
            provenanceSummary: [
                'rag_chunks_count' => 1,
                'rag_degraded' => false,
            ],
        );

        $rendered = (new BoundedAiPromptContext(new SafePromptRenderer))->render(
            systemTemplate: str_repeat('Безопасная инструкция. ', 170),
            userTemplate: "История:\n{{conversation_history}}\n\nКонтекст здоровья:\n{{health_context}}\n\nБаза знаний:\n{{rag_context}}\n\nТекущее сообщение:\n{{current_message}}",
            contextAssembly: $assembly,
            capability: AiCapabilityRegistry::get(AiCapability::ClientCompanion),
            contextPolicy: new AiContextPolicy(includeRag: true, allowRagDegradation: true),
        );

        self::assertCount(1, $rendered['contextAssembly']->ragChunks);
        self::assertFalse((bool) $rendered['contextAssembly']->provenanceSummary['rag_degraded']);
        self::assertTrue((bool) $rendered['contextAssembly']->provenanceSummary['health_context_degraded']);
        self::assertStringContainsString('Qualifying RAG evidence.', $rendered['userPrompt']);
        self::assertStringContainsString('Что показал мой тест?', $rendered['userPrompt']);
    }
}
