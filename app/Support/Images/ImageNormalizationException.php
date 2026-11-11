<?php

namespace App\Support\Images;

use RuntimeException;
use Throwable;

final class ImageNormalizationException extends RuntimeException
{
    public const INVALID = 'invalid';

    public const TOO_LARGE = 'too_large';

    public const TOO_LARGE_DIMENSIONS = 'too_large_dimensions';

    public function __construct(
        public readonly string $reason,
        public readonly ?int $maxBytes = null,
        public readonly ?int $maxPixels = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }
}
