<?php

namespace App\Modules\Security\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class RedactSensitiveLogData implements ProcessorInterface
{
    private const SENSITIVE_KEY_PATTERN = '/secret|token|password|credential|authorization|cookie|session|medical|payment/i';

    private const SENSITIVE_MESSAGE_PATTERN = '/\b(authorization|password|token|secret|cookie|session|medical|payment)\b(\s*[:=]\s*|\s+)[^\s,;]+/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactMessage($record->message),
            context: $this->redactContext($record->context),
            extra: $this->redactContext($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    private function redactContext(array $context): array
    {
        $redacted = [];

        foreach ($context as $key => $value) {
            $key = (string) $key;

            if (preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                $redacted[$key] = '[REDACTED]';

                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redactContext($value) : $value;
        }

        return $redacted;
    }

    private function redactMessage(string $message): string
    {
        return preg_replace(self::SENSITIVE_MESSAGE_PATTERN, '$1=[REDACTED]', $message) ?? $message;
    }
}
