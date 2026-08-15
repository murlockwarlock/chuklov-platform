<?php

namespace App\Modules\Finance\Application;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use Carbon\CarbonImmutable;

final class ListClientFinance
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly ReconcileFinancialObligation $reconciliation,
    ) {}

    /** @return array{obligations: list<array<string, mixed>>, totals: list<array<string, mixed>>} */
    public function handle(?string $locale = null): array
    {
        $client = $this->clientContext->client();
        $obligations = FinancialObligation::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->with(['booking.service', 'ledgerEntries.receipt'])
            ->orderByDesc('created_at')
            ->get();
        $projections = $obligations
            ->map(fn (FinancialObligation $obligation): array => $this->projection($obligation, $client, $locale))
            ->values()
            ->all();
        $totals = [];

        foreach ($projections as $projection) {
            if ((int) $projection['outstandingMinor'] <= 0) {
                continue;
            }

            $currency = (string) $projection['displayCurrency'];
            $totals[$currency] = ($totals[$currency] ?? 0) + (int) $projection['outstandingMinor'];
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
        ];
    }

    /** @return array<string, mixed> */
    private function projection(FinancialObligation $obligation, Client $client, ?string $locale): array
    {
        $reconciliation = $this->reconciliation->handle(
            (int) $obligation->organization_id,
            (int) $obligation->getKey(),
        );
        $entries = $obligation->ledgerEntries->sortBy('id')->values();
        $timezone = $client->timezone;
        $displayApplied = $reconciliation->displayApplied;
        $displayOutstanding = $reconciliation->displayOutstanding;

        $service = $obligation->booking?->service;

        return [
            'serviceName' => $service === null ? (string) ($obligation->price_snapshot['service_name'] ?? 'Услуга') : $service->name,
            'bookingUrl' => $obligation->booking === null ? null : route('portal.bookings.show', $obligation->booking->getKey()),
            'completedAt' => $obligation->booking?->endsAtUtc()->setTimezone($timezone)->format('d.m.Y H:i'),
            'obligationMinor' => (int) $obligation->display_amount_minor,
            'paidMinor' => $displayApplied->minorUnits(),
            'outstandingMinor' => $displayOutstanding->minorUnits(),
            'displayCurrency' => $obligation->display_currency->value,
            'originalCurrency' => $obligation->currency->value,
            'status' => $reconciliation->status->value,
            'statusLabel' => $this->statusLabel($reconciliation->status->value, $locale),
            'history' => $entries->map(fn (FinancialLedgerEntry $entry): array => [
                'amountMinor' => (int) $entry->display_amount_minor,
                'currency' => $entry->display_currency->value,
                'occurredAt' => CarbonImmutable::instance($entry->occurred_at)->setTimezone($timezone)->format('d.m.Y H:i'),
                'methodLabel' => $this->methodLabel($entry->payment_method?->value, $locale),
                'receiptUrl' => $entry->receipt === null
                    ? null
                    : route('portal.finance.receipt', $entry->receipt->getKey()),
            ])->all(),
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

    private function methodLabel(?string $method, ?string $locale): string
    {
        if ($locale === 'en') {
            return match ($method) {
                'cash' => 'Cash',
                'bank_transfer' => 'Bank transfer',
                'manual_card' => 'Card at the clinic',
                'other' => 'Other',
                default => 'Payment correction',
            };
        }

        return match ($method) {
            'cash' => 'Наличные',
            'bank_transfer' => 'Перевод',
            'manual_card' => 'Карта в клинике',
            'other' => 'Другое',
            default => 'Исправление оплаты',
        };
    }
}
