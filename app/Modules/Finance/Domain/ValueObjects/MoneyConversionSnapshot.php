<?php

namespace App\Modules\Finance\Domain\ValueObjects;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use Carbon\CarbonImmutable;

final readonly class MoneyConversionSnapshot
{
    public function __construct(
        public string $sourceAmountMinor,
        public CurrencyCode $sourceCurrency,
        public string $targetAmountMinor,
        public CurrencyCode $targetCurrency,
        public string $rate,
        public ?int $rateId,
        public ?int $rateVersion,
        public ?CarbonImmutable $effectiveAt,
        public FinancialRoundingMode $roundingMode,
        public int $sourceScale,
        public int $targetScale,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_amount_minor' => $this->sourceAmountMinor,
            'source_currency' => $this->sourceCurrency->value,
            'target_amount_minor' => $this->targetAmountMinor,
            'target_currency' => $this->targetCurrency->value,
            'rate' => $this->rate,
            'rate_id' => $this->rateId,
            'rate_version' => $this->rateVersion,
            'effective_at' => $this->effectiveAt?->toIso8601String(),
            'rounding_mode' => $this->roundingMode->value,
            'source_scale' => $this->sourceScale,
            'target_scale' => $this->targetScale,
        ];
    }
}
