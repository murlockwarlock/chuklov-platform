<?php

namespace App\Filament\Resources\GiftCertificates\Pages;

use App\Filament\Resources\GiftCertificates\GiftCertificateResource;
use App\Filament\Support\LocalizedListRecords;

final class ListGiftCertificates extends LocalizedListRecords
{
    protected static string $resource = GiftCertificateResource::class;

    protected static ?string $title = 'Подарочные сертификаты';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
