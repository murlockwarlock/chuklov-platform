<?php

namespace App\Filament\Support;

use App\Filament\Resources\FinancialObligations\FinancialObligationResource;
use App\Models\User;
use App\Modules\Finance\Application\BookingFinanceSummary;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\GetBookingFinanceSummary;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\FinancialReconciliation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use UnexpectedValueException;
use WeakMap;

final class FinancePresentation
{
    /** @var WeakMap<FinancialObligation, FinancialReconciliation|null>|null */
    private static ?WeakMap $reconciliationCache = null;

    /** @var WeakMap<Booking, BookingFinanceSummary|null>|null */
    private static ?WeakMap $bookingSummaryCache = null;

    public function __construct(
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly FinanceAuthorization $authorization,
        private readonly CurrencyConfigurationService $configuration,
        private readonly CurrencyCatalog $catalog,
        private readonly OrganizationContext $context,
        private readonly GetBookingFinanceSummary $bookingFinance,
    ) {}

    public function reconciliation(FinancialObligation $record): ?FinancialReconciliation
    {
        self::$reconciliationCache ??= new WeakMap;

        if (self::$reconciliationCache->offsetExists($record)) {
            return self::$reconciliationCache[$record];
        }

        try {
            $attributes = $record->getAttributes();
            $result = array_key_exists('crm_applied_settlement_minor', $attributes)
                ? $this->reconciliation->handleAggregated(
                    $record,
                    $attributes['crm_applied_settlement_minor'] ?? '0',
                    $attributes['crm_incompatible_ledger_rows'] ?? '0',
                )
                : $this->reconciliation->handle((int) $record->organization_id, (int) $record->getKey());
        } catch (UnexpectedValueException) {
            Log::warning('Finance reconciliation was unavailable for persisted history.', [
                'organization_id' => (int) $record->getRawOriginal('organization_id'),
                'obligation_id' => (int) $record->getKey(),
                'reason_code' => 'invalid_persisted_finance_history',
            ]);
            $result = null;
        }

        self::$reconciliationCache[$record] = $result;

        return $result;
    }

    public function bookingSummary(Booking $booking): ?BookingFinanceSummary
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return null;
        }

        self::$bookingSummaryCache ??= new WeakMap;

        if (self::$bookingSummaryCache->offsetExists($booking)) {
            return self::$bookingSummaryCache[$booking];
        }

        $summary = $this->bookingFinance->handle($actor, $booking);

        self::$bookingSummaryCache[$booking] = $summary;

        return $summary;
    }

    public function canRecordPayment(FinancialObligation $record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && $this->authorization->allowsManage($actor)
            && ($reconciliation = $this->reconciliation($record)) !== null
            && $reconciliation->outstanding->isPositive();
    }

    public function canRecordBookingPayment(Booking $booking): bool
    {
        $actor = auth()->user();
        $summary = $this->bookingSummary($booking);

        return $actor instanceof User
            && $this->authorization->allowsManage($actor)
            && $summary?->reconciliation?->outstanding->isPositive() === true;
    }

    public function canViewFinance(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $this->authorization->allowsView($actor);
    }

    public function status(?FinancialReconciliation $reconciliation): string
    {
        if ($reconciliation === null) {
            return 'Расчёт недоступен';
        }

        return match ($reconciliation->status) {
            FinancialStatus::Settled => 'Оплачено',
            FinancialStatus::PartiallyPaid => 'Оплачено частично',
            FinancialStatus::Outstanding => 'К оплате',
        };
    }

    public function statusColor(?FinancialReconciliation $reconciliation): string
    {
        if ($reconciliation === null) {
            return 'danger';
        }

        return match ($reconciliation->status) {
            FinancialStatus::Settled => 'success',
            FinancialStatus::PartiallyPaid => 'warning',
            FinancialStatus::Outstanding => 'gray',
        };
    }

    public function displayAmount(FinancialObligation $record): string
    {
        return $this->amount($record->display_amount_minor, $record->getRawOriginal('display_currency'));
    }

    public function amount(mixed $minor, mixed $currency): string
    {
        try {
            $code = $this->catalog->code($currency);

            return Money::ofMinor($minor, $code)->toDecimalString().' '.$code->value;
        } catch (InvalidArgumentException) {
            return '—';
        }
    }

    public function money(?Money $money): string
    {
        return $money === null ? '—' : $money->toDecimalString().' '.$money->currency()->value;
    }

    public function settlementOutstanding(FinancialObligation $record): string
    {
        return $this->money($this->reconciliation($record)?->outstanding);
    }

    public function paymentAmountDefault(FinancialObligation $record): ?string
    {
        $reconciliation = $this->reconciliation($record);

        if ($reconciliation === null) {
            return null;
        }

        try {
            $settlementCurrency = $this->catalog->code($record->getRawOriginal('settlement_currency'));
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($reconciliation->outstanding->currency() !== $settlementCurrency) {
            return null;
        }

        return $reconciliation->outstanding->toDecimalString();
    }

    /** @return array<string, string> */
    public function currencyOptions(): array
    {
        try {
            return collect($this->configuration->allowedCurrencies($this->context->id()))
                ->mapWithKeys(fn ($currency): array => [
                    $currency->value => $this->catalog->definition($currency)->name.' ('.$currency->value.')',
                ])
                ->all();
        } catch (ModelNotFoundException|InvalidArgumentException) {
            return [];
        }
    }

    public function singleCurrencyMode(): bool
    {
        try {
            $configuration = $this->configuration->configuration($this->context->id());

            return $configuration->force_single_currency || count($this->configuration->allowedCurrencies($this->context->id())) === 1;
        } catch (ModelNotFoundException) {
            return count($this->configuration->allowedCurrencies($this->context->id())) === 1;
        }
    }

    public function visitDate(?Booking $booking): string
    {
        if ($booking === null) {
            return '—';
        }

        try {
            return $booking->startsAtUtc()
                ->setTimezone($this->context->defaultTimezone())
                ->format('d.m.Y H:i');
        } catch (InvalidArgumentException) {
            return '—';
        }
    }

    public function timestamp(?CarbonInterface $timestamp): string
    {
        return $timestamp === null
            ? '—'
            : $timestamp->copy()->setTimezone($this->context->defaultTimezone())->format('d.m.Y H:i');
    }

    public function currencyName(mixed $currency): string
    {
        try {
            $code = $this->catalog->code($currency);

            return $this->catalog->definition($code)->name.' ('.$code->value.')';
        } catch (InvalidArgumentException) {
            return '—';
        }
    }

    public function historicalRate(FinancialObligation $record): ?string
    {
        $snapshot = $record->conversion_snapshots['display'] ?? null;

        if (! is_array($snapshot) || (! is_string($snapshot['rate'] ?? null) && ! is_int($snapshot['rate'] ?? null))) {
            return null;
        }

        try {
            $source = $this->catalog->code($snapshot['source_currency'] ?? null);
            $target = $this->catalog->code($snapshot['target_currency'] ?? null);
            $rate = (string) $snapshot['rate'];

            return $source === $target ? null : '1 '.$source->value.' = '.$rate.' '.$target->value;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function roundingMode(FinancialObligation $record): ?string
    {
        $snapshot = $record->conversion_snapshots['display'] ?? null;

        if (! is_array($snapshot)) {
            return null;
        }

        try {
            return match (FinancialRoundingMode::fromMixed($snapshot['rounding_mode'] ?? null)) {
                FinancialRoundingMode::HalfUp => 'Обычное математическое',
                FinancialRoundingMode::HalfEven => 'До ближайшего чётного',
                FinancialRoundingMode::Down => 'Вниз, без увеличения суммы',
            };
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function paymentMethodLabel(FinancialLedgerEntry $entry): string
    {
        return match ($entry->entry_type) {
            FinancialLedgerEntryType::Correction => 'Исправление оплаты',
            FinancialLedgerEntryType::FakeGatewaySettlement => 'Тестовая оплата',
            FinancialLedgerEntryType::ManualPayment => match ($entry->payment_method) {
                PaymentMethod::Cash => 'Наличные',
                PaymentMethod::BankTransfer => 'Банковский перевод',
                PaymentMethod::ManualCard => 'Карта в клинике',
                PaymentMethod::Other => 'Другое',
                null => 'Оплата',
            },
        };
    }

    public function bookingAmount(BookingFinanceSummary $summary): string
    {
        return $this->displayAmount($summary->obligation);
    }

    public function bookingPaid(BookingFinanceSummary $summary): string
    {
        return $this->money($summary->reconciliation?->displayApplied);
    }

    public function bookingOutstanding(BookingFinanceSummary $summary): string
    {
        return $this->money($summary->reconciliation?->displayOutstanding);
    }

    public function bookingStatus(BookingFinanceSummary $summary): string
    {
        return $this->status($summary->reconciliation);
    }

    public function bookingStatusColor(BookingFinanceSummary $summary): string
    {
        return $this->statusColor($summary->reconciliation);
    }

    public function bookingPaymentUrl(Booking $booking): ?string
    {
        $summary = $this->bookingSummary($booking);

        return $summary === null
            ? null
            : FinancialObligationResource::getUrl('view', ['record' => $summary->obligation->getKey()]);
    }

    public function clientFinanceUrl(Client $client): string
    {
        return FinancialObligationResource::getUrl('index', [
            'filters' => ['client' => ['value' => $client->getKey()]],
        ]);
    }
}
