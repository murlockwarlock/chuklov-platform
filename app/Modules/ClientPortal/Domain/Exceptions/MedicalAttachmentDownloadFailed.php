<?php

namespace App\Modules\ClientPortal\Domain\Exceptions;

use RuntimeException;
use Throwable;

final class MedicalAttachmentDownloadFailed extends RuntimeException
{
    public function __construct(public readonly string $failureStage, Throwable $previous)
    {
        parent::__construct('Medical attachment download could not be completed.', 0, $previous);
    }
}
