<?php

namespace App\Modules\Analytics\Domain\Enums;

enum AnalyticsTagCandidateStatus: string
{
    case NeedsReview = 'needs_review';
    case Accepted = 'accepted';
    case Renamed = 'renamed';
    case Merged = 'merged';
    case Rejected = 'rejected';
    case Stale = 'stale';
}
