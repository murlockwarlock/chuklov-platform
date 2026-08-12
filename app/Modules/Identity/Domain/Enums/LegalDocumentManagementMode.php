<?php

namespace App\Modules\Identity\Domain\Enums;

enum LegalDocumentManagementMode: string
{
    case PlatformManaged = 'platform_managed';
    case OrganizationManaged = 'organization_managed';
}
