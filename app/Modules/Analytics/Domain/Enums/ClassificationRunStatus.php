<?php

namespace App\Modules\Analytics\Domain\Enums;

enum ClassificationRunStatus: string
{
    case Processing = 'processing';
    case NeedsReview = 'needs_review';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
