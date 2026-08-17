<?php

namespace App\Modules\AI\Application\Validation;

use InvalidArgumentException;

final class EvalInputPrivacyValidator
{
    private const int MAX_DEPTH = 8;

    private const int MAX_BYTES = 65536;

    /** @var list<string> */
    private const PROHIBITED_KEY_FRAGMENTS = [
        'client_id',
        'client',
        'patient',
        'patient_id',
        'person',
        'full_name',
        'email',
        'phone',
        'mobile',
        'telegram_id',
        'external_id',
        'medical_session_id',
        'medical_session',
        'session_id',
        'session',
        'medical_attachment_id',
        'medical_attachment',
        'attachment_id',
        'attachment',
        'survey_attempt_id',
        'survey_attempt',
        'booking_id',
        'booking',
        'organization_id',
        'organization',
        'user_id',
        'user',
        'protected_trace',
        'ai_run_payload',
        'encrypted_',
        'raw_dicom',
    ];

    public function validateClassification(bool $isSynthetic, bool $isDeidentified): void
    {
        if ($isSynthetic === $isDeidentified) {
            throw new InvalidArgumentException('Evaluation case classification must be exactly one: either synthetic or de-identified.');
        }
    }

    /** @param array<string, mixed> $input */
    public function validate(array $input): void
    {
        $encoded = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || strlen($encoded) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Evaluation input is invalid or exceeds the bounded privacy limit.');
        }

        $this->walk($input, '$', 0);
    }

    private function walk(mixed $value, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Evaluation input nesting exceeds the bounded privacy limit.');
        }

        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $this->walk($nested, "{$path}.{$key}", $depth + 1);

                $normalizedKey = strtolower((string) $key);
                $normalizedKey = preg_replace('/[^a-z0-9_]+/', '_', $normalizedKey) ?? $normalizedKey;

                foreach (self::PROHIBITED_KEY_FRAGMENTS as $fragment) {
                    if (str_contains($normalizedKey, $fragment)) {
                        throw new InvalidArgumentException("Production reference '{$path}.{$key}' is prohibited in evaluation input.");
                    }
                }
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        if (preg_match('/[a-zA-Z0-9._%+-]+@(?!example\\.com|test\\.com|synthetic\\.org)[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}/', $value) === 1) {
            throw new InvalidArgumentException('Real email addresses are prohibited in evaluation input.');
        }

        if (preg_match('/\\+?[0-9]{11,15}/', $value) === 1) {
            throw new InvalidArgumentException('Raw phone numbers are prohibited in evaluation input.');
        }
    }
}
