<?php

namespace App\Modules\AI\Domain\Contracts;

interface AiOutputValidatorInterface
{
    /**
     * @param  array<string, mixed>|string|null  $output
     * @param  array<string, mixed>|null  $schema
     */
    public function validate(array|string|null $output, ?array $schema): bool;

    public function getValidationError(): ?string;
}
