<?php

namespace App\Modules\AI\Domain\Contracts;

interface AiPromptRendererInterface
{
    /**
     * @param  array<string, mixed>  $variables
     * @param  list<string>  $allowedVariables
     */
    public function render(string $template, array $variables, array $allowedVariables = []): string;
}
