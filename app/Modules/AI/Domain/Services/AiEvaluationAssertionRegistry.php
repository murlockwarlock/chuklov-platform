<?php

namespace App\Modules\AI\Domain\Services;

use App\Modules\AI\Application\Data\AiEvaluationAssertionResult;
use App\Modules\AI\Domain\Contracts\AiOutputValidatorInterface;
use App\Modules\AI\Domain\Enums\AiEvaluationCheckCategory;
use InvalidArgumentException;

final class AiEvaluationAssertionRegistry
{
    private const int MAX_ASSERTIONS = AiRuntimeLimits::PLATFORM_MAX_EVALUATION_ASSERTIONS;

    private const int MAX_TEXT_LENGTH = 2000;

    private const int MAX_PATH_LENGTH = 120;

    private const int MAX_SCHEMA_DEPTH = 8;

    public function __construct(
        private readonly AiOutputValidatorInterface $schemaValidator,
    ) {}

    /**
     * @param  array<int|string, mixed>  $definitions
     * @return list<array<string, mixed>>
     */
    public function normalize(array $definitions): array
    {
        $normalized = [];

        if (array_key_exists('type', $definitions) && is_string($definitions['type'])) {
            $normalized[] = $this->definition($definitions['type'], $definitions);
        } elseif (array_is_list($definitions)) {
            foreach ($definitions as $definition) {
                if (! is_array($definition)) {
                    throw new InvalidArgumentException('Evaluation assertion definition must be an object.');
                }

                $type = $definition['type'] ?? null;
                if (! is_string($type)) {
                    throw new InvalidArgumentException('Evaluation assertion type is required.');
                }

                $normalized[] = $this->definition($type, $definition);
            }
        } else {
            foreach ($definitions as $type => $value) {
                $normalized = array_merge($normalized, $this->legacyDefinitions((string) $type, $value));
            }
        }

        if (count($normalized) > self::MAX_ASSERTIONS) {
            throw new InvalidArgumentException('An evaluation case exceeds the assertion limit.');
        }

        return $normalized;
    }

    /** @param array<int|string, mixed> $definitions */
    public function validate(array $definitions): void
    {
        $this->normalize($definitions);
    }

    /** @param array<string, mixed>|null $schema */
    public function validateSchema(?array $schema): void
    {
        if ($schema === null) {
            return;
        }

        $this->validateSchemaNode($schema, 0);
    }

    /**
     * @param  array<int|string, mixed>  $definitions
     * @param  array<string, mixed>|null  $expectedSchema
     * @param  array<string, mixed>|null  $outputPayload
     * @param  list<array<string, mixed>>  $ragReferences
     * @return list<AiEvaluationAssertionResult>
     */
    public function evaluate(
        array $definitions,
        ?array $expectedSchema,
        string $outputText,
        ?array $outputPayload,
        array $ragReferences,
    ): array {
        try {
            $assertions = $this->normalize($definitions);
            $this->validateSchema($expectedSchema);
        } catch (InvalidArgumentException $exception) {
            $unknownType = str_contains($exception->getMessage(), 'Unknown evaluation assertion type');

            return [new AiEvaluationAssertionResult(
                type: 'unknown',
                category: AiEvaluationCheckCategory::Assertion,
                passed: false,
                failureCode: $unknownType ? 'unknown_assertion_type' : 'invalid_assertion_definition',
                explanation: $unknownType
                    ? 'Тип проверки не поддерживается и не был выполнен.'
                    : 'Определение проверки некорректно и не было выполнено.',
            )];
        }

        $results = [];
        $expectedSchemaFingerprint = $expectedSchema === null ? null : $this->schemaFingerprint($expectedSchema);
        if ($expectedSchema !== null) {
            $schemaPassed = $this->schemaValidator->validate($outputPayload ?? $outputText, $expectedSchema);
            $results[] = new AiEvaluationAssertionResult(
                type: 'json_schema',
                category: AiEvaluationCheckCategory::Schema,
                passed: $schemaPassed,
                failureCode: $schemaPassed ? null : 'schema_invalid',
                explanation: $schemaPassed
                    ? 'Структура ответа соответствует ожиданиям.'
                    : 'Структура ответа не соответствует ожидаемой схеме.',
            );
        }

        $structuredOutput = $this->structuredOutput($outputText, $outputPayload);
        $searchableOutput = trim($outputText) !== ''
            ? $outputText
            : ($structuredOutput === null ? '' : (json_encode($structuredOutput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''));

        foreach ($assertions as $assertion) {
            if ($expectedSchemaFingerprint !== null
                && ($assertion['type'] ?? null) === 'json_schema'
                && $expectedSchemaFingerprint === $this->schemaFingerprint((array) $assertion['schema'])) {
                continue;
            }

            $results[] = $this->evaluateOne($assertion, $searchableOutput, $structuredOutput, $ragReferences);
        }

        return $results;
    }

    /** @return list<array<string, mixed>> */
    private function legacyDefinitions(string $type, mixed $value): array
    {
        return match ($type) {
            'contains_text', 'required_text' => array_map(
                fn (mixed $text): array => $this->definition('required_text', ['value' => $text]),
                $this->textList($value),
            ),
            'forbidden_text' => array_map(
                fn (mixed $text): array => $this->definition('forbidden_text', ['value' => $text]),
                $this->textList($value),
            ),
            'required_field' => array_map(
                fn (mixed $path): array => $this->definition('required_field', ['path' => $path]),
                $this->pathList($value),
            ),
            default => [$this->definition($type, is_array($value) ? $value : ['value' => $value])],
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function definition(string $type, array $definition): array
    {
        $type = match ($type) {
            'contains_text' => 'required_text',
            'structured_schema' => 'json_schema',
            'output_presence' => 'output_present',
            default => $type,
        };

        $this->validateDefinitionKeys($type, $definition);

        return match ($type) {
            'required_text', 'forbidden_text' => [
                'type' => $type,
                'value' => $this->text($definition['value'] ?? null),
            ],
            'output_present' => [
                'type' => $type,
            ],
            'required_field' => [
                'type' => $type,
                'path' => $this->path($definition['path'] ?? null),
            ],
            'field_value' => $this->fieldValue($definition),
            'json_schema' => [
                'type' => $type,
                'schema' => $this->schema($definition['schema'] ?? null),
            ],
            'required_source', 'forbidden_source' => $this->source($type, $definition),
            default => throw new InvalidArgumentException('Unknown evaluation assertion type.'),
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function fieldValue(array $definition): array
    {
        $path = $this->path($definition['path'] ?? null);
        $operator = $definition['operator'] ?? 'equals';
        if (! is_string($operator) || ! in_array($operator, ['equals', 'enum', 'boolean', 'number_between'], true)) {
            throw new InvalidArgumentException('Evaluation field value operator is not supported.');
        }

        $allowedKeys = match ($operator) {
            'equals', 'boolean' => ['type', 'path', 'operator', 'value'],
            'enum' => ['type', 'path', 'operator', 'values', 'allowed'],
            'number_between' => ['type', 'path', 'operator', 'minimum', 'maximum'],
        };
        $this->assertAllowedKeys($definition, $allowedKeys);
        if ($operator === 'enum' && array_key_exists('values', $definition) && array_key_exists('allowed', $definition)) {
            throw new InvalidArgumentException('Evaluation enum assertion must use one values field.');
        }

        return match ($operator) {
            'equals' => [
                'type' => 'field_value',
                'path' => $path,
                'operator' => $operator,
                'value' => $this->boundedScalar($definition['value'] ?? null),
            ],
            'enum' => [
                'type' => 'field_value',
                'path' => $path,
                'operator' => $operator,
                'values' => $this->boundedScalarList($definition['values'] ?? $definition['allowed'] ?? null),
            ],
            'boolean' => [
                'type' => 'field_value',
                'path' => $path,
                'operator' => $operator,
                'value' => $this->boolean($definition['value'] ?? null),
            ],
            'number_between' => [
                'type' => 'field_value',
                'path' => $path,
                'operator' => $operator,
                ...$this->numberRange($definition),
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function source(string $type, array $definition): array
    {
        if (array_key_exists('source', $definition)) {
            $this->assertAllowedKeys($definition, ['type', 'source']);
        } else {
            $this->assertAllowedKeys($definition, ['type', 'value', 'source_id', 'source_title', 'source_reference']);
            if (array_key_exists('value', $definition)
                && count(array_intersect(array_keys($definition), ['source_id', 'source_title', 'source_reference'])) > 0) {
                throw new InvalidArgumentException('Evaluation source criteria must use one representation.');
            }
        }

        $source = $definition['source'] ?? array_diff_key($definition, ['type' => true]);
        if (! array_key_exists('source', $definition)
            && array_key_exists('value', $definition)
            && ! array_key_exists('source_id', $definition)
            && ! array_key_exists('source_title', $definition)
            && ! array_key_exists('source_reference', $definition)) {
            $source = ['source_title' => $definition['value']];
        }
        if (! is_array($source)) {
            throw new InvalidArgumentException('Evaluation source definition is invalid.');
        }
        $this->assertAllowedKeys($source, ['source_id', 'source_title', 'source_reference']);

        $result = ['type' => $type];
        if (array_key_exists('source_id', $source)) {
            $sourceId = $source['source_id'];
            if (is_string($sourceId)) {
                $normalizedSourceId = ltrim($sourceId, '0');
                if ($normalizedSourceId === '') {
                    $normalizedSourceId = '0';
                }
                if (strlen($normalizedSourceId) > strlen((string) PHP_INT_MAX)
                    || (strlen($normalizedSourceId) === strlen((string) PHP_INT_MAX)
                        && strcmp($normalizedSourceId, (string) PHP_INT_MAX) > 0)
                    || ! ctype_digit($sourceId)) {
                    throw new InvalidArgumentException('Evaluation source ID is invalid.');
                }

                $sourceId = (int) $sourceId;
            }
            if (! is_int($sourceId)) {
                throw new InvalidArgumentException('Evaluation source ID is invalid.');
            }
            if ($sourceId < 1) {
                throw new InvalidArgumentException('Evaluation source ID is invalid.');
            }
            $result['source_id'] = $sourceId;
        }
        foreach (['source_title', 'source_reference'] as $key) {
            if (array_key_exists($key, $source)) {
                $result[$key] = $this->text($source[$key]);
            }
        }
        if (count($result) === 1) {
            throw new InvalidArgumentException('Evaluation source criteria are required.');
        }

        return $result;
    }

    private function text(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Evaluation assertion text is invalid.');
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > self::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException('Evaluation assertion text is invalid.');
        }

        return $value;
    }

    /** @return list<string> */
    private function textList(mixed $value): array
    {
        $values = is_array($value) ? array_values($value) : [$value];
        if ($values === [] || count($values) > self::MAX_ASSERTIONS) {
            throw new InvalidArgumentException('Evaluation assertion text list is invalid.');
        }

        return array_map(fn (mixed $item): string => $this->text($item), $values);
    }

    private function path(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Evaluation JSON path is invalid.');
        }

        $value = trim($value);
        $value = str_starts_with($value, '$.') ? substr($value, 2) : $value;
        if ($value === '' || mb_strlen($value) > self::MAX_PATH_LENGTH || preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/', $value) !== 1) {
            throw new InvalidArgumentException('Evaluation JSON path is invalid.');
        }

        return $value;
    }

    /** @return list<string> */
    private function pathList(mixed $value): array
    {
        $values = is_array($value) ? array_values($value) : [$value];
        if ($values === [] || count($values) > self::MAX_ASSERTIONS) {
            throw new InvalidArgumentException('Evaluation JSON path list is invalid.');
        }

        return array_map(fn (mixed $item): string => $this->path($item), $values);
    }

    /** @return array<string, mixed> */
    private function schema(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Evaluation JSON schema is invalid.');
        }

        $this->validateSchema($value);

        return $value;
    }

    private function boundedScalar(mixed $value): bool|float|int|string|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            if (is_string($value) && mb_strlen($value) > self::MAX_TEXT_LENGTH) {
                throw new InvalidArgumentException('Evaluation field value is too long.');
            }

            return $value;
        }

        if (is_float($value) && is_finite($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Evaluation field value is invalid.');
    }

    /** @return list<bool|float|int|string|null> */
    private function boundedScalarList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > self::MAX_ASSERTIONS) {
            throw new InvalidArgumentException('Evaluation allowed values are invalid.');
        }

        return array_map(fn (mixed $item): bool|float|int|string|null => $this->boundedScalar($item), $value);
    }

    private function boolean(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException('Evaluation boolean value is invalid.');
        }

        return $value;
    }

    private function number(mixed $value): float|int
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || abs((float) $value) > 1_000_000_000) {
            throw new InvalidArgumentException('Evaluation numeric range is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{minimum: float|int, maximum: float|int}
     */
    private function numberRange(array $definition): array
    {
        $minimum = $this->number($definition['minimum'] ?? null);
        $maximum = $this->number($definition['maximum'] ?? null);
        if ($minimum > $maximum) {
            throw new InvalidArgumentException('Evaluation numeric range is invalid.');
        }

        return ['minimum' => $minimum, 'maximum' => $maximum];
    }

    /**
     * @param  array<int|string, mixed>  $definition
     * @param  list<string>  $allowedKeys
     */
    private function assertAllowedKeys(array $definition, array $allowedKeys): void
    {
        foreach (array_keys($definition) as $key) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                throw new InvalidArgumentException('Evaluation assertion contains an unsupported field.');
            }
        }
    }

    /** @param array<int|string, mixed> $definition */
    private function validateDefinitionKeys(string $type, array $definition): void
    {
        $allowedKeys = match ($type) {
            'required_text', 'forbidden_text' => ['type', 'value'],
            'output_present' => ['type'],
            'required_field' => ['type', 'path'],
            'json_schema' => ['type', 'schema'],
            default => null,
        };

        if (is_array($allowedKeys)) {
            $this->assertAllowedKeys($definition, $allowedKeys);
        }
    }

    /** @param array<string, mixed> $schema */
    private function validateSchemaNode(array $schema, int $depth): void
    {
        if ($schema === []) {
            throw new InvalidArgumentException('Evaluation JSON schema cannot be empty.');
        }

        if ($depth > self::MAX_SCHEMA_DEPTH) {
            throw new InvalidArgumentException('Evaluation JSON schema is too deeply nested.');
        }

        foreach (array_keys($schema) as $key) {
            if (! in_array($key, ['type', 'required', 'properties', 'items', 'enum'], true)) {
                throw new InvalidArgumentException('Evaluation JSON schema contains an unsupported keyword.');
            }
        }

        if (array_key_exists('type', $schema) && (! is_string($schema['type']) || ! in_array($schema['type'], ['object', 'array', 'string', 'integer', 'number', 'boolean'], true))) {
            throw new InvalidArgumentException('Evaluation JSON schema type is unsupported.');
        }

        $type = $schema['type'] ?? null;
        if ($type === null && ! array_key_exists('enum', $schema)) {
            throw new InvalidArgumentException('Evaluation JSON schema type is required.');
        }
        if ($type !== 'object' && (array_key_exists('required', $schema) || array_key_exists('properties', $schema))) {
            throw new InvalidArgumentException('Evaluation JSON schema object keywords require an object type.');
        }
        if ($type !== 'array' && array_key_exists('items', $schema)) {
            throw new InvalidArgumentException('Evaluation JSON schema items require an array type.');
        }

        if (array_key_exists('required', $schema) && (! is_array($schema['required']) || ! array_is_list($schema['required']) || count($schema['required']) > self::MAX_ASSERTIONS)) {
            throw new InvalidArgumentException('Evaluation JSON schema required fields are invalid.');
        }
        $requiredFields = [];
        foreach ((array) ($schema['required'] ?? []) as $field) {
            if (! is_string($field) || $this->text($field) === '') {
                throw new InvalidArgumentException('Evaluation JSON schema required fields are invalid.');
            }
            if (isset($requiredFields[$field])) {
                throw new InvalidArgumentException('Evaluation JSON schema required fields must be unique.');
            }
            $requiredFields[$field] = true;
        }

        if (array_key_exists('properties', $schema)) {
            if (! is_array($schema['properties']) || array_is_list($schema['properties']) || count($schema['properties']) > self::MAX_ASSERTIONS) {
                throw new InvalidArgumentException('Evaluation JSON schema properties are invalid.');
            }
            foreach ($schema['properties'] as $propertyName => $property) {
                if (! is_string($propertyName) || $this->text($propertyName) === '' || ! is_array($property)) {
                    throw new InvalidArgumentException('Evaluation JSON schema property is invalid.');
                }
                $this->validateSchemaNode($property, $depth + 1);
            }
        }

        if (array_key_exists('items', $schema)) {
            if (! is_array($schema['items'])) {
                throw new InvalidArgumentException('Evaluation JSON schema items are invalid.');
            }
            $this->validateSchemaNode($schema['items'], $depth + 1);
        }

        if (array_key_exists('enum', $schema)) {
            $this->boundedScalarList($schema['enum']);
        }
    }

    /**
     * @param  array<string, mixed>  $assertion
     * @param  array<string, mixed>|null  $structuredOutput
     * @param  list<array<string, mixed>>  $ragReferences
     */
    private function evaluateOne(array $assertion, string $output, ?array $structuredOutput, array $ragReferences): AiEvaluationAssertionResult
    {
        $type = (string) ($assertion['type'] ?? 'unknown');

        return match ($type) {
            'required_text' => $this->textResult(
                $type,
                ! $this->contains($output, (string) $assertion['value']),
                'required_text_missing',
                'Обязательная информация не найдена в ответе.',
            ),
            'forbidden_text' => $this->textResult(
                $type,
                $this->contains($output, (string) $assertion['value']),
                'forbidden_text_present',
                'В ответе найдена запрещённая информация.',
            ),
            'output_present' => $this->textResult(
                $type,
                trim($output) === '',
                'output_empty',
                'AI вернул пустой ответ.',
            ),
            'required_field' => $this->fieldResult($assertion, $structuredOutput),
            'field_value' => $this->valueResult($assertion, $structuredOutput),
            'json_schema' => $this->schemaResult($assertion, $structuredOutput, $output),
            'required_source', 'forbidden_source' => $this->sourceResult($assertion, $ragReferences),
            default => new AiEvaluationAssertionResult(
                type: 'unknown',
                category: AiEvaluationCheckCategory::Assertion,
                passed: false,
                failureCode: 'unknown_assertion_type',
                explanation: 'Тип проверки не поддерживается и не был выполнен.',
            ),
        };
    }

    private function textResult(string $type, bool $failed, string $failureCode, string $explanation): AiEvaluationAssertionResult
    {
        return new AiEvaluationAssertionResult(
            type: $type,
            category: AiEvaluationCheckCategory::Assertion,
            passed: ! $failed,
            failureCode: $failed ? $failureCode : null,
            explanation: $failed ? $explanation : 'Проверка выполнена.',
        );
    }

    /**
     * @param  array<string, mixed>  $assertion
     * @param  array<string, mixed>|null  $structuredOutput
     */
    private function fieldResult(array $assertion, ?array $structuredOutput): AiEvaluationAssertionResult
    {
        $path = (string) $assertion['path'];
        $found = $this->valueAtPath($structuredOutput, $path, $exists);

        return new AiEvaluationAssertionResult(
            type: 'required_field',
            category: AiEvaluationCheckCategory::Assertion,
            passed: $exists,
            failureCode: $exists ? null : 'required_field_missing',
            explanation: $exists ? 'Обязательное поле найдено.' : 'Обязательное поле структурированного ответа отсутствует.',
            path: $path,
        );
    }

    /**
     * @param  array<string, mixed>  $assertion
     * @param  array<string, mixed>|null  $structuredOutput
     */
    private function valueResult(array $assertion, ?array $structuredOutput): AiEvaluationAssertionResult
    {
        $path = (string) $assertion['path'];
        $actual = $this->valueAtPath($structuredOutput, $path, $exists);
        $operator = (string) $assertion['operator'];
        $passed = $exists && match ($operator) {
            'equals' => $actual === $assertion['value'],
            'enum' => in_array($actual, $assertion['values'], true),
            'boolean' => is_bool($actual) && $actual === $assertion['value'],
            'number_between' => (is_int($actual) || is_float($actual))
                && $actual >= $assertion['minimum'] && $actual <= $assertion['maximum'],
            default => false,
        };
        $failureCode = match ($operator) {
            'enum' => 'value_not_allowed',
            'number_between' => 'value_out_of_range',
            default => 'value_mismatch',
        };

        return new AiEvaluationAssertionResult(
            type: 'field_value',
            category: AiEvaluationCheckCategory::Assertion,
            passed: $passed,
            failureCode: $passed ? null : ($exists ? $failureCode : 'required_field_missing'),
            explanation: $passed ? 'Значение поля соответствует ожиданиям.' : 'Значение структурированного поля не соответствует ожиданиям.',
            path: $path,
        );
    }

    /**
     * @param  array<string, mixed>  $assertion
     * @param  array<string, mixed>|null  $structuredOutput
     */
    private function schemaResult(array $assertion, ?array $structuredOutput, string $output): AiEvaluationAssertionResult
    {
        $passed = $this->schemaValidator->validate($structuredOutput ?? $output, (array) $assertion['schema']);

        return new AiEvaluationAssertionResult(
            type: 'json_schema',
            category: AiEvaluationCheckCategory::Schema,
            passed: $passed,
            failureCode: $passed ? null : 'schema_invalid',
            explanation: $passed ? 'Структура ответа соответствует ожиданиям.' : 'Структура ответа не соответствует ожидаемой схеме.',
        );
    }

    /**
     * @param  array<string, mixed>  $assertion
     * @param  list<array<string, mixed>>  $references
     */
    private function sourceResult(array $assertion, array $references): AiEvaluationAssertionResult
    {
        $found = false;
        foreach ($references as $reference) {
            if ($this->sourceMatches($assertion, $reference)) {
                $found = true;
                break;
            }
        }

        $required = $assertion['type'] === 'required_source';
        $passed = $required ? $found : ! $found;

        return new AiEvaluationAssertionResult(
            type: (string) $assertion['type'],
            category: AiEvaluationCheckCategory::Rag,
            passed: $passed,
            failureCode: $passed ? null : ($required ? 'required_source_not_retrieved' : 'forbidden_source_retrieved'),
            explanation: $passed
                ? 'Проверка источника выполнена по сохранённой истории поиска.'
                : ($required ? 'Ожидаемый источник не был найден в истории поиска.' : 'Запрещённый источник был использован в истории поиска.'),
        );
    }

    /**
     * @param  array<string, mixed>  $assertion
     * @param  array<string, mixed>  $reference
     */
    private function sourceMatches(array $assertion, array $reference): bool
    {
        foreach (['source_id', 'source_title', 'source_reference'] as $key) {
            if (! array_key_exists($key, $assertion)) {
                continue;
            }

            $expected = $assertion[$key];
            $actual = $reference[$key] ?? null;
            if ($key === 'source_id' ? (int) $actual !== (int) $expected : $this->normalizeText((string) $actual) !== $this->normalizeText((string) $expected)) {
                return false;
            }
        }

        return true;
    }

    private function contains(string $haystack, string $needle): bool
    {
        return str_contains($this->normalizeText($haystack), $this->normalizeText($needle));
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    /** @param array<string, mixed> $schema */
    private function schemaFingerprint(array $schema): string
    {
        return hash('sha256', (string) json_encode(
            $this->canonicalizeSchema($schema),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function canonicalizeSchema(mixed $value, ?string $key = null): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $itemKey => $item) {
            $value[$itemKey] = $this->canonicalizeSchema($item, is_string($itemKey) ? $itemKey : $key);
        }

        if (array_is_list($value)) {
            if (in_array($key, ['required', 'enum'], true)) {
                usort($value, static fn (mixed $left, mixed $right): int => strcmp(
                    (string) json_encode($left, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    (string) json_encode($right, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ));
            }

            return $value;
        }

        ksort($value, SORT_STRING);

        return $value;
    }

    /**
     * @param  array<string, mixed>|null  $outputPayload
     * @return array<string, mixed>|null
     */
    private function structuredOutput(string $outputText, ?array $outputPayload): ?array
    {
        if (is_array($outputPayload)) {
            return $outputPayload;
        }

        $decoded = json_decode($outputText, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>|null  $data
     *
     * @param-out bool  $exists
     */
    private function valueAtPath(?array $data, string $path, ?bool &$exists = null): mixed
    {
        $exists = true;
        if ($data === null) {
            $exists = false;

            return null;
        }

        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                $exists = false;

                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
