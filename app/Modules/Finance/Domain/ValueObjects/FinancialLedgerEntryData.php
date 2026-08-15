<?php

namespace App\Modules\Finance\Domain\ValueObjects;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialEntrySource;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use Carbon\CarbonImmutable;

final readonly class FinancialLedgerEntryData
{
    /** @param array<string, mixed>|null $conversionSnapshot */
    public function __construct(
        public FinancialLedgerEntryType $entryType,
        public FinancialEntrySource $source,
        public int $amountMinor,
        public CurrencyCode $currency,
        public int $paymentAmountMinor,
        public CurrencyCode $paymentCurrency,
        public int $baseAmountMinor,
        public CurrencyCode $baseCurrency,
        public int $displayAmountMinor,
        public CurrencyCode $displayCurrency,
        public int $settlementAmountMinor,
        public CurrencyCode $settlementCurrency,
        public ?array $conversionSnapshot,
        public ?PaymentMethod $paymentMethod,
        public CarbonImmutable $occurredAt,
        public ?string $note,
        public ?int $actorUserId,
        public ?string $providerReference,
        public string $idempotencyKey,
        public ?int $correctsLedgerEntryId = null,
    ) {}
}
