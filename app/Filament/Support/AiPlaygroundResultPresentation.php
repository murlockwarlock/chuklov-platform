<?php

namespace App\Filament\Support;

use App\Modules\AI\Application\Data\AiRunResult;

final class AiPlaygroundResultPresentation
{
    public static function body(AiRunResult $result): string
    {
        $payload = $result->outputPayload;
        if (! is_array($payload) && is_string($result->outputText)) {
            $decoded = json_decode($result->outputText, true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        if (is_array($payload)) {
            $lines = [];
            $reply = trim((string) ($payload['reply'] ?? $payload['answer'] ?? ''));
            if ($reply !== '') {
                $lines[] = 'Ответ AI:';
                $lines[] = '«'.$reply.'»';
            }

            $decision = match ((string) ($payload['decision'] ?? '')) {
                'reply', 'answer' => 'Ответить',
                'handoff_required', 'human_handoff' => 'Передать специалисту',
                default => trim((string) ($payload['decision'] ?? '')),
            };
            if ($decision !== '') {
                $lines[] = 'Решение: '.$decision;
            }

            $reason = trim((string) ($payload['handoff_reason'] ?? $payload['reason'] ?? ''));
            if ($reason !== '') {
                $lines[] = 'Причина: '.$reason;
            }

            if ($lines !== []) {
                return implode("\n", $lines);
            }

            return 'Ответ получен в структурированном формате.';
        }

        return trim((string) ($result->outputText ?: 'Ответ получен'));
    }
}
