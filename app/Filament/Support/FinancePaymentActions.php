<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Modules\Finance\Application\CorrectFinancialPayment;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\FinancialReconciliationContract;
use App\Modules\Finance\Application\RecordManualPayment;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class FinancePaymentActions
{
    public static function forObligation(): Action
    {
        return self::recordPaymentAction('recordPayment')
            ->visible(fn (FinancialObligation $record): bool => app(FinancePresentation::class)->canRecordPayment($record));
    }

    public static function forBooking(): Action
    {
        return self::recordPaymentAction('recordBookingPayment')
            ->visible(fn (Booking $record): bool => app(FinancePresentation::class)->canRecordBookingPayment($record));
    }

    public static function openForBooking(): Action
    {
        return Action::make('openPayment')
            ->label('Открыть оплату')
            ->color('gray')
            ->visible(fn (Booking $record): bool => app(FinancePresentation::class)->bookingPaymentUrl($record) !== null)
            ->url(fn (Booking $record): ?string => app(FinancePresentation::class)->bookingPaymentUrl($record));
    }

    public static function correction(): Action
    {
        return Action::make('correctPayment')
            ->label('Исправить оплату')
            ->color('warning')
            ->modalHeading('Исправить оплату')
            ->modalDescription('Исходная оплата останется в истории. Мы добавим исправление и пересчитаем итог.')
            ->modalSubmitActionLabel('Добавить исправление')
            ->schema([
                TextInput::make('payment_summary')
                    ->label('Исправляемая оплата')
                    ->default(fn (FinancialLedgerEntry $record): string => self::paymentSummary($record))
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('reason')
                    ->label('Причина исправления')
                    ->required()
                    ->maxLength(500),
                Hidden::make('idempotency_key')
                    ->default(fn (): string => 'crm-correction-'.Str::uuid()->toString()),
            ])
            ->visible(function (FinancialLedgerEntry $record): bool {
                $actor = auth()->user();

                return $actor instanceof User
                    && app(FinanceAuthorization::class)->allowsManage($actor)
                    && $record->getRawOriginal('entry_type') === 'manual_payment'
                    && self::canCorrect($record)
                    && ! (bool) $record->getAttribute('has_correction');
            })
            ->action(function (FinancialLedgerEntry $record, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                app(CorrectFinancialPayment::class)->handle(
                    actor: $actor,
                    original: $record,
                    reason: (string) $data['reason'],
                    idempotencyKey: (string) $data['idempotency_key'],
                );
                Notification::make()
                    ->success()
                    ->title('Оплата исправлена. Исходная запись сохранена в истории.')
                    ->send();
            });
    }

    private static function recordPaymentAction(string $name): Action
    {
        return Action::make($name)
            ->label('Записать оплату')
            ->color('success')
            ->modalHeading('Записать оплату')
            ->modalSubmitActionLabel('Записать оплату')
            ->schema(self::paymentSchema())
            ->action(function (Model $record, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $obligation = self::obligation($record);
                abort_unless($obligation instanceof FinancialObligation, 404);
                $receipt = $data['receipt'] ?? null;
                abort_unless($receipt === null || $receipt instanceof UploadedFile, 422);
                $presentation = app(FinancePresentation::class);
                $settlementCurrency = self::settlementCurrency($obligation);
                abort_unless($settlementCurrency !== null, 422);
                $currency = $presentation->singleCurrencyMode()
                    ? $settlementCurrency
                    : (string) ($data['currency'] ?? '');

                app(RecordManualPayment::class)->handle(
                    actor: $actor,
                    obligation: $obligation,
                    amount: (string) ($data['amount'] ?? ''),
                    currency: $currency,
                    paymentMethod: (string) ($data['payment_method'] ?? ''),
                    occurredAt: $data['occurred_at'] ?? '',
                    note: isset($data['note']) ? (string) $data['note'] : null,
                    receipt: $receipt,
                    idempotencyKey: (string) $data['idempotency_key'],
                );
                Notification::make()->success()->title('Оплата записана. Остаток обновлён.')->send();
            });
    }

    /** @return list<Component> */
    private static function paymentSchema(): array
    {
        return [
            TextInput::make('client_summary')
                ->label('Клиент')
                ->default(fn (Model $record): string => self::obligation($record)?->client->full_name ?? '—')
                ->disabled()
                ->dehydrated(false),
            TextInput::make('service_summary')
                ->label('Услуга')
                ->default(fn (Model $record): string => self::serviceName($record))
                ->disabled()
                ->dehydrated(false),
            TextInput::make('visit_summary')
                ->label('Дата визита')
                ->default(fn (Model $record): string => app(FinancePresentation::class)->visitDate(self::obligation($record)?->booking))
                ->disabled()
                ->dehydrated(false),
            TextInput::make('remaining_summary')
                ->label('Осталось к оплате')
                ->default(fn (Model $record): string => self::obligation($record) === null
                    ? '—'
                    : app(FinancePresentation::class)->settlementOutstanding(self::obligation($record)))
                ->disabled()
                ->dehydrated(false),
            TextInput::make('amount')
                ->label('Сумма оплаты')
                ->default(fn (Model $record): ?string => self::obligation($record) === null
                    ? null
                    : app(FinancePresentation::class)->paymentAmountDefault(self::obligation($record)))
                ->placeholder('Введите сумму')
                ->inputMode('decimal')
                ->required()
                ->maxLength(40),
            Select::make('currency')
                ->label('Валюта оплаты')
                ->options(fn (): array => app(FinancePresentation::class)->currencyOptions())
                ->default(function (Model $record): ?string {
                    $obligation = self::obligation($record);

                    return $obligation instanceof FinancialObligation
                        ? self::settlementCurrency($obligation)
                        : null;
                })
                ->hidden(fn (): bool => app(FinancePresentation::class)->singleCurrencyMode())
                ->dehydrated(true)
                ->required(),
            Select::make('payment_method')
                ->label('Способ оплаты')
                ->options([
                    PaymentMethod::Cash->value => 'Наличные',
                    PaymentMethod::BankTransfer->value => 'Банковский перевод',
                    PaymentMethod::ManualCard->value => 'Карта в клинике',
                    PaymentMethod::Other->value => 'Другое',
                ])
                ->required(),
            DateTimePicker::make('occurred_at')
                ->label('Дата и время оплаты')
                ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                ->default(fn (): CarbonImmutable => CarbonImmutable::now(app(OrganizationContext::class)->defaultTimezone()))
                ->seconds(false)
                ->required(),
            Textarea::make('note')
                ->label('Примечание')
                ->maxLength(2000),
            FileUpload::make('receipt')
                ->label('Квитанция')
                ->helperText('PDF, JPG или PNG до 10 МБ.')
                ->storeFiles(false)
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                ->maxSize(10240),
            Hidden::make('idempotency_key')
                ->default(fn (): string => 'crm-payment-'.Str::uuid()->toString()),
        ];
    }

    private static function obligation(Model $record): ?FinancialObligation
    {
        if ($record instanceof FinancialObligation) {
            return $record;
        }

        if ($record instanceof Booking) {
            return app(FinancePresentation::class)->bookingSummary($record)?->obligation;
        }

        return null;
    }

    private static function serviceName(Model $record): string
    {
        $obligation = self::obligation($record);

        return $obligation?->service->name
            ?? $obligation?->booking?->service->name
            ?? '—';
    }

    private static function paymentSummary(FinancialLedgerEntry $entry): string
    {
        $presentation = app(FinancePresentation::class);

        try {
            $currency = app(FinancialReconciliationContract::class)->currency($entry->getRawOriginal('payment_currency'));
            $amount = app(FinancialReconciliationContract::class)
                ->money($entry->getRawOriginal('payment_amount_minor'), $currency, 'A persisted ledger payment amount is invalid.')
                ->toDecimalString().' '.$currency->value;
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            $amount = '—';
        }

        return $amount.' · '.$presentation->timestamp($entry->occurred_at).' · '.$presentation->paymentMethodLabel($entry);
    }

    private static function settlementCurrency(FinancialObligation $obligation): ?string
    {
        try {
            return app(CurrencyCatalog::class)->code($obligation->getRawOriginal('settlement_currency'))->value;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function canCorrect(FinancialLedgerEntry $entry): bool
    {
        try {
            app(FinancialReconciliationContract::class)->validateCorrectableLedgerEntry($entry);

            return true;
        } catch (\UnexpectedValueException) {
            return false;
        }
    }
}
