<?php

namespace App\Modules\B2B\Domain\Enums;

enum B2bFormConfigurationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
