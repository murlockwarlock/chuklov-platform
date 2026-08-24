<?php

namespace App\Modules\Referrals\Domain\ValueObjects;

final readonly class PaidConversionEvidence
{
    public function __construct(
        public int $organizationId,
        public int $clientId,
        public int $obligationId,
        public int $ledgerEntryId,
        public string $financeStatus,
        public bool $authoritativeSettled,
        public string $source = 'finance',
    ) {}
}
