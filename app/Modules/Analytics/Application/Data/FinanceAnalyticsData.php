<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class FinanceAnalyticsData
{
    /** @param list<array{label: string, account_type: string, currency: string, movement_currency: string, movement_minor: string}> $accounts */
    public function __construct(
        public bool $available,
        public string $baseCurrency,
        public ?string $revenueMinor,
        public ?string $averageReceiptMinor,
        public ?string $realizedLtvMinor,
        public ?string $debtMinor,
        public int $receiptCount,
        public int $cohortClientCount,
        public ?string $expensesMinor = null,
        public ?string $cashMovementMinor = null,
        public array $accounts = [],
    ) {}

    public static function unavailable(): self
    {
        return new self(
            available: false,
            baseCurrency: '',
            revenueMinor: null,
            averageReceiptMinor: null,
            realizedLtvMinor: null,
            debtMinor: null,
            receiptCount: 0,
            cohortClientCount: 0,
            expensesMinor: null,
            cashMovementMinor: null,
            accounts: [],
        );
    }
}
