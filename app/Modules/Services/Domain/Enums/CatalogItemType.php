<?php

namespace App\Modules\Services\Domain\Enums;

enum CatalogItemType: string
{
    case Service = 'service';
    case PhysicalProduct = 'physical_product';
    case OnlineProduct = 'online_product';
}
