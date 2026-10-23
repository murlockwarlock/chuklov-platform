<?php

namespace App\Modules\Security\Infrastructure\Logging;

use DateTimeInterface;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

class RedactSensitiveLogData implements ProcessorInterface
{
    private const SAFE_OPERATION_CODES = [
        'booking.create',
        'booking.create_multiple',
        'cancel',
        'client.email_auth.code_delivery',
        'client.portal.logout',
        'create',
        'download',
        'fake_gateway_initiation',
        'financial_correction',
        'gateway_initiation',
        'gift_certificate_correction',
        'gift_certificate_redemption',
        'manual_payment',
        'portal.medical-attachments.download',
        'portal.medical-attachments.upload',
        'reconcile',
        'recreate',
        'referral_credit_redemption',
        'referral_credit_restore',
        'replacement',
        'request_referral_payout',
        'start_survey_attempt',
        'upload',
        'update',
        'view_survey_attempt',
    ];

    private const SENSITIVE_KEY_PATTERN = '/secret|token|password|credential|authorization|cookie|session|medical|payment|anamnesis|complaint|medicine|supplement|operation|injury|clinical|chronic|symptom|pain[_-]?(vas|not_measured)|goal|api[_-]?key|access[_-]?key|private[_-]?key|client[_-]?secret|bearer|prompt|retrieved_content|rag_chunk|tool_payload/i';

    private const SESSION_CLINICAL_KEY_PATTERN = '/^(pain|tests|observations|root_cause_hypothesis|protocol|result)$/i';

    private const SESSION_CLINICAL_MESSAGE_PATTERN = '/(?P<boundary>^|\s|[\[{(])(?P<prefix>medical_session|clinical)\.(?P<field>pain|tests|observations|root_cause_hypothesis|protocol|result)\s*[:=]\s*["\']?(?P<value>[^\r\n}\]"\'\s]+(?:\s[^\r\n}\]"\'\s]+)*?)(?=\s+(?:medical_session|clinical)\.(?:pain|tests|observations|root_cause_hypothesis|protocol|result)\s*[:=]|[\]}),;]|[\s]*$|\s*\z)/iu';

    private const AUTHORIZATION_MESSAGE_PATTERN = '/\b(authorization|proxy-authorization)\b(?:\s*[:=]\s*["\']?|\s+)[^\r\n}\]]+/i';

    private const SENSITIVE_MESSAGE_PATTERN = '/\b(x-api-key|api[_-]?key|access[_-]?key|client[_-]?secret|refresh[_-]?token|password|token|secret|credential|cookie|session|medical|payment|anamnesis|complaint|medicine|supplement|operation|injury|chronic_conditions|clinical_answers|symptoms|pain_vas|pain_not_measured|goals)\b(?:\s*[:=]\s*["\']?|\s+)[^\r\n}\]]+/i';

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

            if ($this->isSensitiveKey($key, $value)) {
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

    private function isSensitiveKey(string $key, mixed $value): bool
    {
        if ($key === 'operation' && is_string($value) && in_array($value, self::SAFE_OPERATION_CODES, true)) {
            return false;
        }

        return preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1
            || preg_match(self::SESSION_CLINICAL_KEY_PATTERN, $key) === 1;
    }

    private function redactMessage(string $message): string
    {
        $message = preg_replace('/\bfamily_[A-Za-z0-9_-]{43}(?![A-Za-z0-9_-])/', 'family_[REDACTED]', $message) ?? $message;
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

        $message = preg_replace_callback(
            self::SESSION_CLINICAL_MESSAGE_PATTERN,
            static fn (array $matches): string => $matches['boundary'].$matches['prefix'].'.'.$matches['field'].'=[REDACTED]',
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
