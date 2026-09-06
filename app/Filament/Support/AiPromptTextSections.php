<?php

namespace App\Filament\Support;

final class AiPromptTextSections
{
    public static function source(string $prompt): string
    {
        [$source] = self::split($prompt);

        return $source === '' ? 'Источник не выделен.' : $source;
    }

    public static function guardrails(string $prompt): string
    {
        [, $guardrails] = self::split($prompt);

        return $guardrails === '' ? 'Защитные правила отдельно не выделены.' : $guardrails;
    }

    /** @return array{0: string, 1: string} */
    private static function split(string $prompt): array
    {
        $source = trim($prompt);
        $guardrails = [];

        foreach ([
            "\n\n[PLATFORM SAFETY GUARDRAIL]\n",
            "\n\n[SYSTEM-OWNED SAFETY POLICY]\n",
        ] as $marker) {
            if (! str_contains($source, $marker)) {
                continue;
            }

            [$source, $policy] = explode($marker, $source, 2);
            $guardrails[] = trim($policy);
        }

        $source = preg_replace('/^\[SOURCE TEXT\]\s*/u', '', trim($source)) ?? trim($source);

        return [trim($source), trim(implode("\n\n", array_filter($guardrails)))];
    }
}
