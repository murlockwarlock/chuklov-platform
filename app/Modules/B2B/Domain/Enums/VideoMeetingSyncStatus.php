<?php

namespace App\Modules\B2B\Domain\Enums;

enum VideoMeetingSyncStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
    case CancellationPending = 'cancellation_pending';
    case ReconciliationRequired = 'reconciliation_required';
}
