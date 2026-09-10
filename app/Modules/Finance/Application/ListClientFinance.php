<?php

namespace App\Modules\Finance\Application;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

final class ListClientFinance
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly FinancialReconciliationContract $contract,
    ) {}

    /** @return array{obligations: list<array<string, mixed>>, totals: list<array<string, mixed>>, hasUnavailableObligations: bool} */
    public function handle(?string $locale = null): array
    {
        $client = $this->clientContext->client();
        $obligations = FinancialObligation::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->with([
                'booking.service',
                'ledgerEntries.receipt',
                'gatewayTransactions' => function ($query): void {
                    $query
                        ->where('gateway', 'fake')
                        ->orderByDesc('id');
                },
            ])
            ->orderByDesc('created_at')
            ->get();
        $projections = $obligations
            ->map(fn (FinancialObligation $obligation): array => $this->projection($obligation, $client, $locale))
            ->values()
            ->all();
        $totals = [];

        foreach ($projections as $projection) {
            if ($projection['available'] !== true || ! is_int($projection['outstandingMinor']) || $projection['outstandingMinor'] <= 0) {
                continue;
            }

            $currency = $projection['displayCurrency'];

            if (! is_string($currency) || $currency === '') {
                continue;
            }

            $totals[$currency] = ($totals[$currency] ?? 0) + $projection['outstandingMinor'];
        }

        return [
            'obligations' => array_values($projections),
            'totals' => array_values(collect($totals)
                ->sortKeys()
                ->map(fn (int $amountMinor, string $currency): array => [
                    'amountMinor' => $amountMinor,
                    'currency' => $currency,
                ])
                ->values()
                ->all()),
            'hasUnavailableObligations' => collect($projections)->contains(
                static fn (array $projection): bool => $projection['available'] !== true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function projection(FinancialObligation $obligation, Client $client, ?string $locale): array
    {
        try {
            $reconciliation = $this->reconciliation->handle(
                (int) $obligation->organization_id,
                (int) $obligation->getKey(),
            );
            $data = $this->contract->validateObligation($obligation);
        } catch (UnexpectedValueException) {
            Log::warning('Client finance reconciliation was unavailable for persisted history.', [
                'organization_id' => (int) $obligation->getRawOriginal('organization_id'),
                'client_id' => (int) $client->getKey(),
                'obligation_id' => (int) $obligation->getKey(),
                'reason_code' => 'invalid_persisted_finance_history',
            ]);

            return $this->unavailableProjection($obligation, $client, $locale);
        }

        $entries = $obligation->ledgerEntries->sortBy('id')->values();
        $timezone = $client->timezone;
        $displayApplied = $reconciliation->displayApplied;
        $displayOutstanding = $reconciliation->displayOutstanding;

        return [
            ...$this->context($obligation, $client),
            'available' => true,
            'obligationMinor' => $data['amounts']['display_amount_minor'],
            'paidMinor' => $displayApplied->minorUnits(),
            'outstandingMinor' => $displayOutstanding->minorUnits(),
            'displayCurrency' => $data['currencies']['display_currency']->value,
            'originalCurrency' => $data['currencies']['currency']->value,
            'status' => $reconciliation->status->value,
            'statusLabel' => $this->statusLabel($reconciliation->status->value, $locale),
            'history' => $entries->map(fn (FinancialLedgerEntry $entry): array => $this->historyEntry($entry, $timezone, $locale))->all(),
            'demoPayment' => $this->demoPayment($obligation, $reconciliation->outstanding->minorUnits() > 0, $locale),
        ];
    }

    /** @return array<string, mixed> */
    private function unavailableProjection(FinancialObligation $obligation, Client $client, ?string $locale): array
    {
        $displayCurrency = $this->contract->tryCurrency($obligation->getRawOriginal('display_currency'));
        $originalCurrency = $this->contract->tryCurrency($obligation->getRawOriginal('currency'));
        $timezone = $client->timezone;

        return [
            ...$this->context($obligation, $client),
            'available' => false,
            'obligationMinor' => null,
            'paidMinor' => null,
            'outstandingMinor' => null,
            'displayCurrency' => $displayCurrency?->value,
            'originalCurrency' => $originalCurrency?->value,
            'status' => 'unavailable',
            'statusLabel' => $this->unavailableLabel($locale),
            'history' => $obligation->ledgerEntries
                ->sortBy('id')
                ->values()
                ->map(fn (FinancialLedgerEntry $entry): array => $this->historyEntry($entry, $timezone, $locale))->all(),
            'demoPayment' => null,
        ];
    }

    /** @return array{stateLabel: string, canStart: bool, canSucceed: bool, canFail: bool, canRefund: bool, startUrl: string, successUrl: ?string, failUrl: ?string, refundUrl: ?string}|null */
    private function demoPayment(FinancialObligation $obligation, bool $hasOutstanding, ?string $locale): ?array
    {
        if (app()->environment('production') || ! config('payments.fake_enabled', false)) {
            return null;
        }

        $transaction = $obligation->gatewayTransactions->first();
        if (! $hasOutstanding && $transaction === null) {
            return null;
        }

        $status = $transaction?->status->value;
        $canStart = $hasOutstanding && ($transaction === null || in_array($status, ['failed', 'refunded'], true));
        $canSucceed = $status === 'pending';
        $canFail = $status === 'pending';
        $canRefund = $status === 'settled';

        return [
            'stateLabel' => $this->demoStateLabel($status, $locale),
            'canStart' => $canStart,
            'canSucceed' => $canSucceed,
            'canFail' => $canFail,
            'canRefund' => $canRefund,
            'startUrl' => route('portal.finance.fake.start', $obligation->getKey()),
            'successUrl' => $transaction === null ? null : route('portal.finance.fake.simulate', ['transactionId' => $transaction->getKey(), 'outcome' => 'success']),
            'failUrl' => $transaction === null ? null : route('portal.finance.fake.simulate', ['transactionId' => $transaction->getKey(), 'outcome' => 'fail']),
            'refundUrl' => $transaction === null ? null : route('portal.finance.fake.simulate', ['transactionId' => $transaction->getKey(), 'outcome' => 'refund']),
        ];
    }

    /** @return array{serviceName: string, bookingUrl: ?string, completedAt: ?string} */
    private function context(FinancialObligation $obligation, Client $client): array
    {
        $priceSnapshot = $obligation->getAttribute('price_snapshot');
        $service = $obligation->booking?->service;

        return [
            'serviceName' => $service === null
                ? (is_array($priceSnapshot) && is_string($priceSnapshot['service_name'] ?? null)
                    ? $priceSnapshot['service_name']
                    : 'Услуга')
                : $service->name,
            'bookingUrl' => $obligation->booking === null ? null : route('portal.bookings.show', $obligation->booking->getKey()),
            'completedAt' => $obligation->booking?->endsAtUtc()->setTimezone($client->timezone)->format('d.m.Y H:i'),
        ];
    }

    /** @return array{available: bool, amountMinor: ?int, currency: ?string, occurredAt: string, methodLabel: string, receiptUrl: ?string} */
    private function historyEntry(FinancialLedgerEntry $entry, string $timezone, ?string $locale): array
    {
        $currency = $this->contract->tryCurrency($entry->getRawOriginal('display_currency'));
        $amountMinor = null;
        $ledgerAvailable = true;

        try {
            $this->contract->validateLedgerForReconciliation($entry);
        } catch (UnexpectedValueException) {
            $ledgerAvailable = false;
        }

        if ($ledgerAvailable && $currency !== null) {
            try {
                $amountMinor = $this->contract->money(
                    $entry->getRawOriginal('display_amount_minor'),
                    $currency,
                    'A persisted ledger display amount is invalid.',
                )->minorUnits();
            } catch (UnexpectedValueException) {
                $amountMinor = null;
            }
        }

        [$methodLabel, $methodAvailable] = $this->methodLabel(
            $entry->getRawOriginal('entry_type'),
            $entry->getRawOriginal('payment_method'),
            $locale,
        );

        return [
            'available' => $ledgerAvailable && $amountMinor !== null && $currency !== null && $methodAvailable,
            'amountMinor' => $amountMinor,
            'currency' => $currency?->value,
            'occurredAt' => CarbonImmutable::instance($entry->occurred_at)->setTimezone($timezone)->format('d.m.Y H:i'),
            'methodLabel' => $methodLabel,
            'receiptUrl' => $entry->receipt === null
                ? null
                : route('portal.finance.receipt', $entry->receipt->getKey()),
        ];
    }

    private function statusLabel(string $status, ?string $locale): string
    {
        if ($locale === 'en') {
            return match ($status) {
                'partially_paid' => 'Partially paid',
                'settled' => 'Paid',
                default => 'Outstanding',
            };
        }

        return match ($status) {
            'partially_paid' => 'Оплачено частично',
            'settled' => 'Оплачено',
            default => 'К оплате',
        };
    }

    private function unavailableLabel(?string $locale): string
    {
        return $locale === 'en' ? 'Calculation unavailable' : 'Расчёт недоступен';
    }

    private function demoStateLabel(?string $status, ?string $locale): string
    {
        if ($locale === 'en') {
            return match ($status) {
                'pending' => 'Demo payment is waiting for a result',
                'failed' => 'Demo payment failed',
                'settled' => 'Demo payment completed',
                'refunded' => 'Demo payment refunded',
                default => 'Demo payment is ready',
            };
        }

        return match ($status) {
            'pending' => 'Демо-оплата ожидает результата',
            'failed' => 'Демо-оплата не прошла',
            'settled' => 'Демо-оплата завершена',
            'refunded' => 'Демо-оплата возвращена',
            default => 'Демо-оплата готова к запуску',
        };
    }

    /** @return array{0: string, 1: bool} */
    private function methodLabel(mixed $entryType, mixed $method, ?string $locale): array
    {
        $labels = $locale === 'en'
            ? [
                'cash' => 'Cash',
                'bank_transfer' => 'Bank transfer',
                'manual_card' => 'Card at the clinic',
                'other' => 'Other',
                'correction' => 'Payment correction',
                'fake_gateway_settlement' => 'Test payment',
            ]
            : [
                'cash' => 'Наличные',
                'bank_transfer' => 'Перевод',
                'manual_card' => 'Карта в клинике',
                'other' => 'Другое',
                'correction' => 'Исправление оплаты',
                'fake_gateway_settlement' => 'Тестовая оплата',
            ];

        if ($entryType === 'manual_payment' && is_string($method) && in_array($method, [
            'cash',
            'bank_transfer',
            'manual_card',
            'other',
        ], true)) {
            return [$labels[$method], true];
        }

        if (is_string($entryType) && array_key_exists($entryType, $labels)) {
            return [$labels[$entryType], $method === null];
        }

        return [$locale === 'en' ? 'Payment unavailable' : 'Платёж недоступен', false];
    }
}
