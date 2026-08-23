<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Domain\Services\AiEvaluationAssertionRegistry;
use InvalidArgumentException;
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

        $this->expectException(InvalidArgumentException::class);
        $this->registry->normalize([['type' => 'arbitrary_expression', 'expression' => '1 == 1']]);
    }

    public function test_rag_source_ids_are_bounded_and_integer_like(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry->normalize([[
            'type' => 'required_source',
            'source_id' => str_repeat('9', 20),
        ]]);
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

    public function test_text_matching_is_normalized_and_structured_values_are_strict_with_null_and_missing_distinguished(): void
    {
        $results = $this->registry->evaluate(
            definitions: [
                ['type' => 'required_text', 'value' => '  Согласие   специалиста '],
                ['type' => 'forbidden_text', 'value' => 'подтверждённый  диагноз'],
                ['type' => 'required_field', 'path' => 'nullable'],
                ['type' => 'required_field', 'path' => 'missing'],
                ['type' => 'field_value', 'path' => 'nullable', 'operator' => 'equals', 'value' => null],
                ['type' => 'field_value', 'path' => 'number', 'operator' => 'number_between', 'minimum' => 1, 'maximum' => 5],
                ['type' => 'field_value', 'path' => 'flag', 'operator' => 'boolean', 'value' => true],
            ],
            expectedSchema: null,
            outputText: 'СОГЛАСИЕ специалиста получено; диагноз не подтверждён.',
            outputPayload: [
                'nullable' => null,
                'number' => '3',
                'flag' => 1,
            ],
            ragReferences: [],
        );

        self::assertTrue($results[0]->passed);
        self::assertTrue($results[1]->passed);
        self::assertTrue($results[2]->passed);
        self::assertFalse($results[3]->passed);
        self::assertTrue($results[4]->passed);
        self::assertFalse($results[5]->passed);
        self::assertFalse($results[6]->passed);
    }

    public function test_schema_is_bounded_and_unknown_keywords_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->validateSchema([
            'type' => 'object',
            'properties' => [
                'nested' => [
                    'type' => 'object',
                    'properties' => [
                        'deeper' => [
                            'type' => 'object',
                            'properties' => [
                                'too_deep' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'unknown_keyword' => true,
        ]);
    }

    public function test_schema_depth_and_assertion_count_overflow_are_rejected(): void
    {
        $schema = ['type' => 'object'];
        for ($depth = 0; $depth < 10; $depth++) {
            $schema = ['type' => 'object', 'properties' => ['nested' => $schema]];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->registry->validateSchema($schema);
    }

    public function test_assertion_count_overflow_is_rejected(): void
    {
        $assertions = [];
        for ($index = 0; $index < 33; $index++) {
            $assertions[] = ['type' => 'required_text', 'value' => 'value '.$index];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->registry->normalize($assertions);
    }

    public function test_malformed_schema_evaluation_does_not_pass_silently(): void
    {
        $results = $this->registry->evaluate(
            definitions: [],
            expectedSchema: ['type' => 'object', 'unsupported' => true],
            outputText: '{}',
            outputPayload: [],
            ragReferences: [],
        );

        self::assertCount(1, $results);
        self::assertFalse($results[0]->passed);
        self::assertSame('invalid_assertion_definition', $results[0]->failureCode);
    }

    public function test_expected_schema_is_not_reported_twice_when_the_same_schema_is_explicitly_asserted(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['summary'],
            'properties' => ['summary' => ['type' => 'string']],
        ];
        $results = $this->registry->evaluate(
            definitions: [['type' => 'json_schema', 'schema' => $schema]],
            expectedSchema: $schema,
            outputText: '{"summary":"ok"}',
            outputPayload: ['summary' => 'ok'],
            ragReferences: [],
        );

        self::assertCount(1, $results);
        self::assertTrue($results[0]->passed);
    }

    public function test_json_schema_supports_strict_scalar_json_outputs(): void
    {
        $stringResult = $this->registry->evaluate(
            definitions: [['type' => 'json_schema', 'schema' => ['type' => 'string']]],
            expectedSchema: null,
            outputText: '"approved"',
            outputPayload: null,
            ragReferences: [],
        );
        $numberResult = $this->registry->evaluate(
            definitions: [['type' => 'json_schema', 'schema' => ['type' => 'number']]],
            expectedSchema: null,
            outputText: '3.5',
            outputPayload: null,
            ragReferences: [],
        );

        self::assertTrue($stringResult[0]->passed);
        self::assertTrue($numberResult[0]->passed);
    }
}
