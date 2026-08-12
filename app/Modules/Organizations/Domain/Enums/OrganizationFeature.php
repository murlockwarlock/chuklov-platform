<?php

namespace App\Modules\Organizations\Domain\Enums;

enum OrganizationFeature: string
{
    case ClientRecords = 'client_records';
    case ServiceCatalog = 'service_catalog';
}
