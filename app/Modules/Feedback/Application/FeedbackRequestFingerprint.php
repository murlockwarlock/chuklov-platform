<?php

namespace App\Modules\Feedback\Application;

use LogicException;

final class FeedbackRequestFingerprint
{
    /** @param array{client_id: int, score: int, internal_feedback: string|null, source: string, theme_ids?: list<int>} $payload */
    public function handle(array $payload): string
    {
        $applicationKey = (string) config('app.key');

        if ($applicationKey === '') {
            throw new LogicException('The application key is required for feedback request fingerprints.');
        }

        $canonicalPayload = json_encode(
            [...$payload, 'theme_ids' => array_values($payload['theme_ids'] ?? [])],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $fingerprintKey = hash_hmac('sha256', 'feedback.request-fingerprint', $applicationKey, true);

        return hash_hmac('sha256', $canonicalPayload, $fingerprintKey);
    }
}
