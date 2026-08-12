<?php

namespace App\Modules\Identity\Domain\Enums;

enum LegalDocumentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
