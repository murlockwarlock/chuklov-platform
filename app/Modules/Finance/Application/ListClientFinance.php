<?php

namespace App\Modules\Finance\Application;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
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
        private readonly ResolveLavaPaymentSellable $sellable,
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
                'purchase.items.fulfillment',
                'ledgerEntries.receipt',
                'gatewayTransactions' => fn ($query) => $query->orderByDesc('id'),
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
            ...$this->context($obligation, $client, $locale),
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
            'lavaPayment' => $this->lavaPayment($obligation, $reconciliation->outstanding->minorUnits() > 0, $client, $locale),
        ];
    }

    /** @return array<string, mixed> */
    private function unavailableProjection(FinancialObligation $obligation, Client $client, ?string $locale): array
    {
        $displayCurrency = $this->contract->tryCurrency($obligation->getRawOriginal('display_currency'));
        $originalCurrency = $this->contract->tryCurrency($obligation->getRawOriginal('currency'));
        $timezone = $client->timezone;

        return [
            ...$this->context($obligation, $client, $locale),
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
            'lavaPayment' => null,
        ];
    }

    /** @return array{stateLabel: string, canStart: bool, canSucceed: bool, canFail: bool, canRefund: bool, startUrl: string, successUrl: ?string, failUrl: ?string, refundUrl: ?string}|null */
    private function demoPayment(FinancialObligation $obligation, bool $hasOutstanding, ?string $locale): ?array
    {
        if (app()->environment('production') || ! config('payments.fake_enabled', false)) {
            return null;
        }

        $transaction = $obligation->gatewayTransactions
            ->first(static fn ($transaction): bool => $transaction->gateway === 'fake');
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

    /** @return array{stateLabel: string, canStart: bool, canContinue: bool, checkoutUrl: ?string, startUrl: string, poll: bool}|null */
    private function lavaPayment(
        FinancialObligation $obligation,
        bool $hasOutstanding,
        Client $client,
        ?string $locale,
    ): ?array {
        $target = $this->sellable->handle($obligation);
        if (! $hasOutstanding || $target === null) {
            return null;
        }

        $currency = $obligation->payment_currency->value;
        $mappingCount = in_array($currency, ['RUB', 'USD', 'EUR'], true)
            ? PaymentProviderOfferMapping::query()
                ->activeFor((int) $obligation->organization_id, 'lava', (string) $target['type'], (int) $target['id'], $currency)
                ->count()
            : 0;
        $available = $mappingCount === 1 && filter_var($client->email, FILTER_VALIDATE_EMAIL) !== false;
        $lavaTransactions = $obligation->gatewayTransactions
            ->filter(static fn ($transaction): bool => $transaction->gateway === 'lava');
        $transaction = $lavaTransactions
            ->first(static fn ($transaction): bool => in_array($transaction->status, [
                PaymentGatewayStatus::Initiating,
                PaymentGatewayStatus::Pending,
                PaymentGatewayStatus::Unknown,
            ], true))
            ?? $lavaTransactions->first();
        $status = $transaction?->status;
        $checkoutUrl = $transaction?->checkout_url;
        $checkoutParts = is_string($checkoutUrl) ? parse_url($checkoutUrl) : false;
        $checkoutUrl = is_string($checkoutUrl)
            && filter_var($checkoutUrl, FILTER_VALIDATE_URL) !== false
            && is_array($checkoutParts)
            && ($checkoutParts['scheme'] ?? null) === 'https'
            && is_string($checkoutParts['host'] ?? null)
            ? $checkoutUrl
            : null;
        $canContinue = $status === PaymentGatewayStatus::Pending && $checkoutUrl !== null;
        $canStart = $available && ($transaction === null || in_array($status, [
            PaymentGatewayStatus::Failed,
            PaymentGatewayStatus::Refunded,
            PaymentGatewayStatus::Settled,
        ], true));
        $poll = in_array($status, [PaymentGatewayStatus::Initiating, PaymentGatewayStatus::Pending, PaymentGatewayStatus::Unknown], true);

        return [
            'stateLabel' => $this->lavaStateLabel($status, $available || $canContinue, $locale),
            'canStart' => $canStart,
            'canContinue' => $canContinue,
            'checkoutUrl' => $canContinue ? $checkoutUrl : null,
            'startUrl' => route('portal.finance.lava.start', $obligation->getKey()),
            'poll' => $poll,
        ];
    }

    /** @return array{serviceName: string, bookingUrl: ?string, completedAt: ?string, purchaseFulfillment: array{statusLabel: string, message: string, accessUrl: ?string}|null} */
    private function context(FinancialObligation $obligation, Client $client, ?string $locale): array
    {
        $priceSnapshot = $obligation->getAttribute('price_snapshot');
        $service = $obligation->booking?->service;
        $item = $obligation->purchase?->items->first();
        $productSnapshot = $item?->product_snapshot;
        $purchaseName = is_array($productSnapshot) && is_string($productSnapshot['name'] ?? null)
            ? $productSnapshot['name']
            : (is_array($productSnapshot) && is_string($productSnapshot['plan_name'] ?? null)
                ? $productSnapshot['plan_name']
                : null);

        return [
            'serviceName' => $service === null
                ? ($purchaseName
                    ?? (is_array($priceSnapshot) && is_string($priceSnapshot['service_name'] ?? null)
                        ? $priceSnapshot['service_name']
                        : 'Покупка'))
                : $service->name,
            'bookingUrl' => $obligation->booking === null ? null : route('portal.bookings.show', $obligation->booking->getKey()),
            'completedAt' => $obligation->booking?->endsAtUtc()->setTimezone($client->timezone)->format('d.m.Y H:i'),
            'purchaseFulfillment' => $this->purchaseFulfillment($obligation, $locale),
        ];
    }

    /** @return array{statusLabel: string, message: string, accessUrl: ?string}|null */
    private function purchaseFulfillment(FinancialObligation $obligation, ?string $locale): ?array
    {
        $purchase = $obligation->purchase;
        $fulfillment = $purchase?->items->first()?->fulfillment;
        if ($purchase === null || $fulfillment === null) {
            return null;
        }

        if ($purchase->status !== PurchaseStatus::Paid) {
            return [
                'statusLabel' => $locale === 'en' ? 'Waiting for payment' : 'Ожидает оплаты',
                'message' => $locale === 'en' ? 'Access will be prepared after payment.' : 'Доступ будет подготовлен после оплаты.',
                'accessUrl' => null,
            ];
        }

        $isTracker = $fulfillment->provider_type === 'tracker_entitlement';

        return match ($fulfillment->status) {
            CommerceFulfillmentStatus::Fulfilled => [
                'statusLabel' => $isTracker
                    ? ($locale === 'en' ? 'Access active' : 'Доступ активен')
                    : ($locale === 'en' ? 'Access granted' : 'Доступ выдан'),
                'message' => $isTracker
                    ? ($locale === 'en' ? 'Your Tracker access is active.' : 'Доступ к Трекеру активен.')
                    : ($locale === 'en' ? 'The product access has been granted.' : 'Доступ к продукту выдан.'),
                'accessUrl' => null,
            ],
            CommerceFulfillmentStatus::Failed => [
                'statusLabel' => $locale === 'en' ? 'Access issue' : 'Ошибка выдачи',
                'message' => $locale === 'en' ? 'The team will check the access issue.' : 'Команда проверит выдачу доступа.',
                'accessUrl' => null,
            ],
            default => [
                'statusLabel' => $locale === 'en' ? 'Access pending' : 'Требуется выдача',
                'message' => $isTracker
                    ? ($locale === 'en' ? 'Tracker access is being prepared.' : 'Доступ к Трекеру подготавливается.')
                    : ($locale === 'en' ? 'Access will be granted by a specialist or administrator.' : 'Доступ будет выдан специалистом или администратором.'),
                'accessUrl' => null,
            ],
        };
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

    private function lavaStateLabel(?PaymentGatewayStatus $status, bool $available, ?string $locale): string
    {
        if (! $available) {
            return $locale === 'en' ? 'Online payment is unavailable' : 'Онлайн-оплата недоступна';
        }

        if ($locale === 'en') {
            return match ($status) {
                PaymentGatewayStatus::Pending => 'Payment is waiting for confirmation',
                PaymentGatewayStatus::Initiating => 'Payment is being checked',
                PaymentGatewayStatus::Unknown => 'Payment needs review',
                PaymentGatewayStatus::Failed => 'Payment failed',
                PaymentGatewayStatus::Refunded => 'Payment can be started again',
                default => 'Pay online',
            };
        }

        return match ($status) {
            PaymentGatewayStatus::Pending => 'Оплата ожидает подтверждения',
            PaymentGatewayStatus::Initiating => 'Проверяем оплату',
            PaymentGatewayStatus::Unknown => 'Платёж требует проверки',
            PaymentGatewayStatus::Failed => 'Оплата не прошла',
            PaymentGatewayStatus::Refunded => 'Оплату можно начать снова',
            default => 'Оплатить онлайн',
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
                'gateway_settlement' => 'Online payment',
            ]
            : [
                'cash' => 'Наличные',
                'bank_transfer' => 'Перевод',
                'manual_card' => 'Карта в клинике',
                'other' => 'Другое',
                'correction' => 'Исправление оплаты',
                'fake_gateway_settlement' => 'Тестовая оплата',
                'gateway_settlement' => 'Оплата через платёжный сервис',
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
