<?php

namespace App\Modules\B2B\Infrastructure\Video;

use RuntimeException;

final class VideoMeetingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $safeCode,
        public readonly bool $retryable = true,
        public readonly bool $outcomeUnknown = false,
        public readonly bool $requiresReconciliation = false,
    ) {
        parent::__construct($message);
    }

    public static function retryable(string $code, bool $outcomeUnknown = false): self
    {
        return new self('The video meeting provider request failed.', $code, true, $outcomeUnknown);
    }

    public static function permanent(string $code): self
    {
        return new self('The video meeting provider rejected the request.', $code, false);
    }

    public static function reconciliationRequired(string $code): self
    {
        return new self('The video meeting provider response requires reconciliation.', $code, false, true, true);
    }
}
