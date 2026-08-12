<?php

namespace App\Modules\Organizations\Domain\Enums;

enum OrganizationSettingKey: string
{
    case DefaultLanguage = 'default_language';
    case DefaultTimezone = 'default_timezone';

    public function type(): OrganizationSettingType
    {
        return OrganizationSettingType::String;
    }
}
