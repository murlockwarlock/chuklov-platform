<?php

namespace App\Modules\AI\Infrastructure\Output;

use App\Modules\AI\Domain\Contracts\AiOutputValidatorInterface;

class JsonSchemaOutputValidator implements AiOutputValidatorInterface
{
    private ?string $lastError = null;

    /**
     * @param  array<string, mixed>|string|null  $output
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>  $context
     */
    public function validate(array|string|null $output, ?array $schema, array $context = []): bool
    {
        $this->lastError = null;

        $decodedOutput = $output;
        if (is_string($output) && (($schema !== null && $schema !== [])
            || ($context['session_preparation_mode'] ?? null) === 'factual_session_preparation')) {
            $decoded = json_decode($output, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->lastError = 'Output is not valid JSON: '.json_last_error_msg();

                return false;
            }
            $decodedOutput = $decoded;
        }

        if ($schema !== null && $schema !== [] && ! $this->validateNode($decodedOutput, $schema)) {
            return false;
        }

        if (($context['session_preparation_mode'] ?? null) === 'factual_session_preparation'
            && ! $this->validateFactualSessionPreparation($decodedOutput)) {
            return false;
        }

        return true;
    }

    public function getValidationError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function validateNode(mixed $data, array $schema): bool
    {
        if (array_key_exists('anyOf', $schema)) {
            $variants = $schema['anyOf'];
            if (! is_array($variants) || $variants === []) {
                $this->lastError = 'Schema anyOf must contain at least one schema.';

                return false;
            }

            foreach ($variants as $variant) {
                if (is_array($variant) && $this->validateNode($data, $variant)) {
                    return true;
                }
            }

            $this->lastError = 'Value does not match any allowed schema.';

            return false;
        }

        if (array_key_exists('enum', $schema) && ! in_array($data, (array) $schema['enum'], true)) {
            $this->lastError = 'Value is not in the allowed enum.';

            return false;
        }

        $expectedType = $schema['type'] ?? null;

        if ($expectedType !== null) {
            if ($expectedType === 'object') {
                if (! is_array($data) || (count($data) > 0 && array_is_list($data))) {
                    $this->lastError = 'Expected JSON object.';

                    return false;
                }

                $requiredFields = (array) ($schema['required'] ?? []);
                foreach ($requiredFields as $field) {
                    if (! array_key_exists((string) $field, $data)) {
                        $this->lastError = "Missing required field: {$field}";

                        return false;
                    }
                }

                $properties = (array) ($schema['properties'] ?? []);
                foreach ($properties as $propKey => $propSchema) {
                    if (array_key_exists((string) $propKey, $data) && is_array($propSchema)) {
                        if (! $this->validateNode($data[$propKey], $propSchema)) {
                            return false;
                        }
                    }
                }
            } elseif ($expectedType === 'array') {
                if (! is_array($data) || (count($data) > 0 && ! array_is_list($data))) {
                    $this->lastError = 'Expected JSON array.';

                    return false;
                }

                $itemSchema = $schema['items'] ?? null;
                if (is_array($itemSchema)) {
                    foreach ($data as $item) {
                        if (! $this->validateNode($item, $itemSchema)) {
                            return false;
                        }
                    }
                }
                if (array_key_exists('minItems', $schema) && count($data) < (int) $schema['minItems']) {
                    $this->lastError = 'Array has fewer items than allowed.';

                    return false;
                }
                if (array_key_exists('maxItems', $schema) && count($data) > (int) $schema['maxItems']) {
                    $this->lastError = 'Array has more items than allowed.';

                    return false;
                }
            } elseif ($expectedType === 'string' && ! is_string($data)) {
                $this->lastError = 'Expected string value.';

                return false;
            } elseif ($expectedType === 'integer' && ! is_int($data)) {
                $this->lastError = 'Expected integer value.';

                return false;
            } elseif ($expectedType === 'number' && (! is_int($data) && ! is_float($data) || ! is_finite((float) $data))) {
                $this->lastError = 'Expected number value.';

                return false;
            } elseif ($expectedType === 'boolean' && ! is_bool($data)) {
                $this->lastError = 'Expected boolean value.';

                return false;
            } elseif ($expectedType === 'null' && $data !== null) {
                $this->lastError = 'Expected null value.';

                return false;
            }
        }

        if (array_key_exists('minimum', $schema)
            && (is_int($data) || is_float($data))
            && $data < $schema['minimum']) {
            $this->lastError = 'Number is below the allowed minimum.';

            return false;
        }
        if (array_key_exists('maximum', $schema)
            && (is_int($data) || is_float($data))
            && $data > $schema['maximum']) {
            $this->lastError = 'Number is above the allowed maximum.';

            return false;
        }

        return true;
    }

    private function validateFactualSessionPreparation(mixed $output): bool
    {
        if (! is_array($output) || (count($output) > 0 && array_is_list($output))) {
            $this->lastError = 'Factual session preparation must return a JSON object.';

            return false;
        }

        $allowedFields = [
            'client_summary',
            'main_request',
            'source_facts',
            'hypotheses',
            'critical_limitations_risks',
            'blind_spots_questions',
            'recommended_first_session_focus',
            'missing_information',
        ];
        foreach (array_keys($output) as $field) {
            if (! in_array((string) $field, $allowedFields, true)) {
                $this->lastError = "Factual session preparation contains an unsupported field: {$field}.";

                return false;
            }
        }

        foreach ([
            'hypotheses',
            'critical_limitations_risks',
            'recommended_first_session_focus',
        ] as $field) {
            if (array_key_exists($field, $output) && $output[$field] !== []) {
                $this->lastError = "Factual session preparation cannot contain {$field}.";

                return false;
            }
        }

        $prohibitedPath = $this->prohibitedFieldPath($output);
        if ($prohibitedPath !== null) {
            $this->lastError = "Factual session preparation contains a prohibited field: {$prohibitedPath}.";

            return false;
        }

        return true;
    }

    private function prohibitedFieldPath(mixed $value, string $path = ''): ?string
    {
        $prohibitedFields = [
            'diagnosis',
            'diagnoses',
            'root_cause',
            'root_causes',
            'recommendation',
            'recommendations',
            'protocol',
            'protocols',
            'prognosis',
            'prognoses',
            'red_flag',
            'red_flags',
            'clinical_evaluation',
            'clinical_evaluations',
            'index',
            'indices',
            'score',
            'scores',
        ];

        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $key => $child) {
            $key = (string) $key;
            $childPath = $path === '' ? $key : $path.'.'.$key;
            $normalizedKey = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $key));
            if (in_array($normalizedKey, $prohibitedFields, true)) {
                return $childPath;
            }

            $nestedPath = $this->prohibitedFieldPath($child, $childPath);
            if ($nestedPath !== null) {
                return $nestedPath;
            }
        }

        return null;
    }
}
