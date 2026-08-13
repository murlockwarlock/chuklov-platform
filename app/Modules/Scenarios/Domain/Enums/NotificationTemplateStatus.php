<?php

namespace App\Modules\Scenarios\Domain\Enums;

enum NotificationTemplateStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
