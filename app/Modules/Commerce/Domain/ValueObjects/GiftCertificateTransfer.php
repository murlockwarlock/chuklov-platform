<?php

namespace App\Modules\Commerce\Domain\ValueObjects;

use App\Modules\Commerce\Domain\Models\GiftCertificateClaim;

final readonly class GiftCertificateTransfer
{
    public function __construct(
        public GiftCertificateClaim $claim,
        public string $url,
        public string $rawToken,
    ) {}
}
