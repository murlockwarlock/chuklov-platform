<?php

namespace App\Filament\Resources\GiftCertificates\Pages;

use App\Filament\Resources\GiftCertificates\GiftCertificateResource;
use App\Filament\Support\LocalizedViewRecord;

final class ViewGiftCertificate extends LocalizedViewRecord
{
    protected static string $resource = GiftCertificateResource::class;

    protected static ?string $title = 'Подарочный сертификат';
}
