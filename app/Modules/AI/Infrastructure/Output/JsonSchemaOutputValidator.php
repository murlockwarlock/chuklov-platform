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

        if (! is_array($output)) {
            $this->lastError = 'Output must be an array or object structure.';

            return false;
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
            } elseif ($expectedType === 'string' && ! is_string($data)) {
                $this->lastError = 'Expected string value.';

                return false;
            } elseif ($expectedType === 'integer' && ! is_int($data)) {
                $this->lastError = 'Expected integer value.';

                return false;
            } elseif ($expectedType === 'number' && ! is_numeric($data)) {
                $this->lastError = 'Expected number value.';

                return false;
            } elseif ($expectedType === 'boolean' && ! is_bool($data)) {
                $this->lastError = 'Expected boolean value.';

                return false;
            }
        }

        return true;
    }
}
