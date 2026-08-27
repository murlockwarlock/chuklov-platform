<?php

namespace App\Modules\B2B\Domain\Enums;

enum VideoMeetingOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Cancel = 'cancel';
    case Reconcile = 'reconcile';
    case Recreate = 'recreate';
}
