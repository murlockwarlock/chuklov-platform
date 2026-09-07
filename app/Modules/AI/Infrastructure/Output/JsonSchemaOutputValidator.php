<?php

namespace App\Modules\AI\Infrastructure\Output;

use App\Modules\AI\Domain\Contracts\AiOutputValidatorInterface;

class JsonSchemaOutputValidator implements AiOutputValidatorInterface
{
    private ?string $lastError = null;

    /**
     * @param  array<string, mixed>|string|null  $output
     * @param  array<string, mixed>|null  $schema
     */
    public function validate(array|string|null $output, ?array $schema): bool
    {
        $this->lastError = null;

        if ($schema === null || empty($schema)) {
            return true;
        }

        if (is_string($output)) {
            $decoded = json_decode($output, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->lastError = 'Output is not valid JSON: '.json_last_error_msg();

                return false;
            }
            $output = $decoded;
        }

        return $this->validateNode($output, $schema);
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
}
