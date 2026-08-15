<?php

namespace App\Modules\Security\Infrastructure\Logging;

use DateTimeInterface;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

class RedactSensitiveLogData implements ProcessorInterface
{
    private const SENSITIVE_KEY_PATTERN = '/secret|token|password|credential|authorization|cookie|session|medical|payment|anamnesis|complaint|medicine|supplement|operation|injury|pain|root[_-]?cause[_-]?hypothesis|observations|api[_-]?key|access[_-]?key|private[_-]?key|client[_-]?secret|bearer/i';

    private const AUTHORIZATION_MESSAGE_PATTERN = '/\b(authorization|proxy-authorization)\b(?:\s*[:=]\s*["\']?|\s+)[^\r\n}\]]+/i';

    private const SENSITIVE_MESSAGE_PATTERN = '/\b(x-api-key|api[_-]?key|access[_-]?key|client[_-]?secret|refresh[_-]?token|password|token|secret|credential|cookie|session|medical|payment|anamnesis|complaint|medicine|supplement|operation|injury)\b(?:\s*[:=]\s*["\']?|\s+)[^\r\n}\]]+/i';

    private const AUTH_SCHEME_PATTERN = '/\b(Bearer|Basic)\s+[^\r\n}\]]+/i';

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

            $redacted[$key] = match (true) {
                $value instanceof Throwable => $this->redactThrowable($value),
                is_array($value) => $this->redactContext($value),
                is_string($value) => $this->redactMessage($value),
                $value instanceof DateTimeInterface => $value,
                is_object($value) => '[REDACTED OBJECT]',
                default => $value,
            };
        }

        return $redacted;
    }

    private function redactMessage(string $message): string
    {
        $message = preg_replace_callback(
            self::AUTHORIZATION_MESSAGE_PATTERN,
            static fn (array $matches): string => $matches[1].'=[REDACTED]',
            $message,
        ) ?? $message;

        $message = preg_replace_callback(
            self::SENSITIVE_MESSAGE_PATTERN,
            static fn (array $matches): string => $matches[1].'=[REDACTED]',
            $message,
        ) ?? $message;

        return preg_replace(self::AUTH_SCHEME_PATTERN, '$1 [REDACTED]', $message) ?? $message;
    }

    /** @return array{class: class-string<Throwable>, message: string, code: int|string} */
    private function redactThrowable(Throwable $throwable): array
    {
        return [
            'class' => $throwable::class,
            'message' => '[REDACTED]',
            'code' => $throwable->getCode(),
        ];
    }
}
