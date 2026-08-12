<?php

namespace App\Modules\Organizations\Domain\Enums;

enum OrganizationSettingType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
}
