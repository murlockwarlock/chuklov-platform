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

    public static function runtimeContract(string $prompt): string
    {
        $marker = "\n\n[CURRENT RUNTIME CONTRACT]\n";
        if (! str_contains($prompt, $marker)) {
            return 'Runtime-контракт отдельно не выделен.';
        }

        [, $runtime] = explode($marker, $prompt, 2);

        return trim($runtime) === '' ? 'Runtime-контракт отдельно не выделен.' : trim($runtime);
    }

    /** @return array{0: string, 1: string} */
    private static function split(string $prompt): array
    {
        $source = trim($prompt);
        $guardrails = [];

        foreach ([
            "\n\n[PLATFORM SAFETY GUARDRAIL]\n",
            "\n\n[CURRENT PLATFORM SAFETY GUARDRAILS]\n",
            "\n\n[SYSTEM-OWNED SAFETY POLICY]\n",
        ] as $marker) {
            if (! str_contains($source, $marker)) {
                continue;
            }

            [$source, $policy] = explode($marker, $source, 2);
            if (str_contains($policy, "\n\n[CURRENT RUNTIME CONTRACT]\n")) {
                [$policy] = explode("\n\n[CURRENT RUNTIME CONTRACT]\n", $policy, 2);
            }
            $guardrails[] = trim($policy);
        }

        if (str_contains($source, "\n\n[CURRENT RUNTIME CONTRACT]\n")) {
            [$source] = explode("\n\n[CURRENT RUNTIME CONTRACT]\n", $source, 2);
        }

        $source = preg_replace('/^\[SOURCE TEXT\]\s*/u', '', trim($source)) ?? trim($source);

        return [trim($source), trim(implode("\n\n", array_filter($guardrails)))];
    }
}
