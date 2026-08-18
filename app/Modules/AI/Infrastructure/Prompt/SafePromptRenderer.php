<?php

namespace App\Modules\AI\Infrastructure\Prompt;

use App\Modules\AI\Domain\Contracts\AiPromptRendererInterface;

class SafePromptRenderer implements AiPromptRendererInterface
{
    /**
     * @param  array<string, mixed>  $variables
     * @param  list<string>  $allowedVariables
     */
    public function render(string $template, array $variables, array $allowedVariables = []): string
    {
        if ($template === '') {
            return '';
        }

        $checkAllowed = ! empty($allowedVariables);
        $allowedMap = array_fill_keys($allowedVariables, true);

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\-]+)\s*\}\}|\{\s*([a-zA-Z0-9_\-]+)\s*\}/', function (array $matches) use ($variables, $checkAllowed, $allowedMap) {
            $key = $matches[1] !== '' ? $matches[1] : $matches[2];

            if ($checkAllowed && ! isset($allowedMap[$key])) {
                return $matches[0];
            }

            if (! array_key_exists($key, $variables)) {
                return '';
            }

            $val = $variables[$key];

            if (is_array($val)) {
                return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
            }

            if (is_bool($val)) {
                return $val ? 'true' : 'false';
            }

            return (string) $val;
        }, $template) ?? $template;
    }
}
