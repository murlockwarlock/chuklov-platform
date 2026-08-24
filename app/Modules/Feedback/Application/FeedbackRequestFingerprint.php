<?php

namespace App\Modules\Feedback\Application;

use LogicException;

final class FeedbackRequestFingerprint
{
    /** @param array{client_id: int, score: int, internal_feedback: string|null, source: string} $payload */
    public function handle(array $payload): string
    {
        $applicationKey = (string) config('app.key');

        if ($applicationKey === '') {
            throw new LogicException('The application key is required for feedback request fingerprints.');
        }

        $canonicalPayload = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $fingerprintKey = hash_hmac('sha256', 'feedback.request-fingerprint', $applicationKey, true);

        return hash_hmac('sha256', $canonicalPayload, $fingerprintKey);
    }
}
