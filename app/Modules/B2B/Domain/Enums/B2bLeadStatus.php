<?php

namespace App\Modules\B2B\Domain\Enums;

enum B2bLeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case ZoomScheduled = 'zoom_scheduled';
    case Closed = 'closed';
}
