<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Domain\Services\AiEvaluationAssertionRegistry;
use Tests\TestCase;

final class AiEvaluationAssertionRegistryTest extends TestCase
{
    private AiEvaluationAssertionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = app(AiEvaluationAssertionRegistry::class);
    }

    public function test_required_forbidden_and_presence_assertions_are_typed_and_bounded(): void
    {
        $results = $this->registry->evaluate(
            definitions: [
                ['type' => 'required_text', 'value' => 'согласие специалиста'],
                ['type' => 'forbidden_text', 'value' => 'подтверждённый диагноз'],
                ['type' => 'output_present'],
            ],
            expectedSchema: null,
            outputText: 'Нужно получить согласие специалиста до следующего шага.',
            outputPayload: null,
            ragReferences: [],
        );

        self::assertCount(3, $results);
        self::assertTrue($results[0]->passed);
        self::assertTrue($results[1]->passed);
        self::assertTrue($results[2]->passed);
        self::assertSame('required_text', $results[0]->type);
        self::assertNull($results[0]->failureCode);

        $failed = $this->registry->evaluate(
            definitions: [
                ['type' => 'required_text', 'value' => 'согласие специалиста'],
                ['type' => 'forbidden_text', 'value' => 'подтверждённый диагноз'],
            ],
            expectedSchema: null,
            outputText: 'Подтверждённый диагноз указан без согласия.',
            outputPayload: null,
            ragReferences: [],
        );

        self::assertSame('required_text_missing', $failed[0]->failureCode);
        self::assertSame('forbidden_text_present', $failed[1]->failureCode);
        self::assertStringNotContainsString('подтверждённый диагноз', $failed[1]->explanation);
    }

    public function test_structured_schema_fields_and_bounded_values_are_checked_without_expressions(): void
    {
        $definitions = [
            ['type' => 'json_schema', 'schema' => [
                'type' => 'object',
                'required' => ['summary', 'risk'],
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'risk' => ['type' => 'object'],
                ],
            ]],
            ['type' => 'required_field', 'path' => 'risk.level'],
            ['type' => 'field_value', 'path' => 'risk.level', 'operator' => 'enum', 'values' => ['low', 'medium']],
            ['type' => 'field_value', 'path' => 'risk.confirmed', 'operator' => 'boolean', 'value' => false],
            ['type' => 'field_value', 'path' => 'risk.score', 'operator' => 'number_between', 'minimum' => 0, 'maximum' => 10],
        ];
        $payload = [
            'summary' => 'Нужна консультация',
            'risk' => ['level' => 'low', 'confirmed' => false, 'score' => 3.5],
        ];

        $passed = $this->registry->evaluate($definitions, null, json_encode($payload, JSON_THROW_ON_ERROR), $payload, []);

        self::assertCount(5, $passed);
        self::assertTrue(collect($passed)->every(static fn ($result): bool => $result->passed));

        $failed = $this->registry->evaluate(
            definitions: [
                ['type' => 'required_field', 'path' => 'risk.level'],
                ['type' => 'field_value', 'path' => 'risk.level', 'operator' => 'enum', 'values' => ['low']],
                ['type' => 'field_value', 'path' => 'risk.score', 'operator' => 'number_between', 'minimum' => 0, 'maximum' => 10],
            ],
            expectedSchema: ['type' => 'object', 'required' => ['summary']],
            outputText: '{"risk":{"level":"high","score":11}}',
            outputPayload: ['risk' => ['level' => 'high', 'score' => 11]],
            ragReferences: [],
        );

        self::assertSame('schema_invalid', $failed[0]->failureCode);
        self::assertSame('value_not_allowed', $failed[2]->failureCode);
        self::assertSame('value_out_of_range', $failed[3]->failureCode);
    }

    public function test_unknown_assertion_type_fails_closed_and_rag_source_checks_use_provenance(): void
    {
        $unknown = $this->registry->evaluate(
            definitions: [['type' => 'execute_php', 'code' => 'return true;']],
            expectedSchema: null,
            outputText: 'Ответ',
            outputPayload: null,
            ragReferences: [],
        );

        self::assertFalse($unknown[0]->passed);
        self::assertSame('unknown_assertion_type', $unknown[0]->failureCode);

        $rag = $this->registry->evaluate(
            definitions: [
                ['type' => 'required_source', 'source_title' => 'Клинический протокол'],
                ['type' => 'forbidden_source', 'source_id' => 99],
            ],
            expectedSchema: null,
            outputText: 'Ответ со ссылкой на разрешённый материал.',
            outputPayload: null,
            ragReferences: [[
                'source_id' => 12,
                'source_title' => 'Клинический протокол',
                'source_reference' => 'protocol://approved',
            ]],
        );

        self::assertTrue($rag[0]->passed);
        self::assertTrue($rag[1]->passed);
        self::assertSame('rag', $rag[0]->category->value);

        $this->expectException(\InvalidArgumentException::class);
        $this->registry->normalize([['type' => 'arbitrary_expression', 'expression' => '1 == 1']]);
    }

    public function test_single_canonical_assertion_object_is_normalized_without_treating_its_fields_as_types(): void
    {
        self::assertSame([
            ['type' => 'required_text', 'value' => 'approved'],
        ], $this->registry->normalize([
            'type' => 'required_text',
            'value' => 'approved',
        ]));
    }
}
