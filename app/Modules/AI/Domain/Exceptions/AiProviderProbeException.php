<?php

namespace App\Modules\AI\Domain\Exceptions;

use App\Modules\AI\Domain\Enums\AiErrorCategory;
use RuntimeException;
use Throwable;

final class AiProviderProbeException extends RuntimeException
{
    public function __construct(
        public readonly AiErrorCategory $category,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Provider connectivity probe failed.', 0, $previous);
    }
}
