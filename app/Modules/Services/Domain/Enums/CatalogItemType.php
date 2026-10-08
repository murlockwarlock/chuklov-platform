<?php

namespace App\Modules\Services\Domain\Enums;

enum CatalogItemType: string
{
    case GiftCertificate = 'gift_certificate';
    case Service = 'service';
    case PhysicalProduct = 'physical_product';
    case OnlineProduct = 'online_product';
}
