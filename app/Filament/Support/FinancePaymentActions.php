<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Modules\Commerce\Application\ApplyGiftCertificateForStaff;
use App\Modules\Commerce\Application\CorrectGiftCertificateRedemption;
use App\Modules\Commerce\Application\GiftCertificateBalanceProjection;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Finance\Application\CorrectFinancialPayment;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\FinancialReconciliationContract;
use App\Modules\Finance\Application\RecordManualPayment;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Application\ApplyReferralCreditForStaff;
use App\Modules\Referrals\Application\RestoreReferralCredit;
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
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Component as LivewireComponent;

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

    public static function referralCreditForObligation(): Action
    {
        return self::referralCreditAction('applyReferralCredit')
            ->visible(fn (FinancialObligation $record): bool => app(FinancePresentation::class)->canApplyReferralCredit($record));
    }

    public static function referralCreditForBooking(): Action
    {
        return self::referralCreditAction('applyBookingReferralCredit')
            ->visible(fn (Booking $record): bool => app(FinancePresentation::class)->canApplyReferralCreditForBooking($record));
    }

    public static function giftCertificateForObligation(): Action
    {
        return self::giftCertificateAction('applyGiftCertificate')
            ->visible(fn (FinancialObligation $record): bool => app(FinancePresentation::class)->canApplyGiftCertificate($record));
    }

    public static function giftCertificateForBooking(): Action
    {
        return self::giftCertificateAction('applyBookingGiftCertificate')
            ->visible(fn (Booking $record): bool => app(FinancePresentation::class)->canApplyGiftCertificateForBooking($record));
    }

    public static function openForBooking(): Action
    {
        return Action::make('openPayment')
            ->label(__('Открыть оплату'))
            ->color('gray')
            ->visible(fn (Booking $record): bool => app(FinancePresentation::class)->bookingPaymentUrl($record) !== null)
            ->url(fn (Booking $record): ?string => app(FinancePresentation::class)->bookingPaymentUrl($record));
    }

    public static function correction(): Action
    {
        return Action::make('correctPayment')
            ->label(__('Исправить оплату'))
            ->color('warning')
            ->modalHeading(__('Исправить оплату'))
            ->modalDescription(__('Исходная оплата останется в истории. Мы добавим исправление и пересчитаем итог.'))
            ->modalSubmitActionLabel(__('Добавить исправление'))
            ->schema([
                TextInput::make('payment_summary')
                    ->label(__('Исправляемая оплата'))
                    ->default(fn (FinancialLedgerEntry $record): string => self::paymentSummary($record))
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('reason')
                    ->label(__('Причина исправления'))
                    ->required()
                    ->maxLength(500),
                Hidden::make('idempotency_key')
                    ->default(fn (): string => 'crm-correction-'.Str::uuid()->toString()),
            ])
            ->visible(function (FinancialLedgerEntry $record): bool {
                $actor = auth()->user();

                return $actor instanceof User
                    && app(FinanceAuthorization::class)->allowsManage($actor)
                    && in_array($record->getRawOriginal('entry_type'), [
                        FinancialLedgerEntryType::ManualPayment->value,
                        FinancialLedgerEntryType::ReferralCredit->value,
                        FinancialLedgerEntryType::GiftCertificateRedemption->value,
                    ], true)
                    && self::canCorrect($record)
                    && ! (bool) $record->getAttribute('has_correction');
            })
            ->action(function (Action $action, FinancialLedgerEntry $record, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                if ($record->getRawOriginal('entry_type') === FinancialLedgerEntryType::ReferralCredit->value) {
                    app(RestoreReferralCredit::class)->handle(
                        actor: $actor,
                        entry: $record,
                        reason: (string) $data['reason'],
                        idempotencyKey: (string) $data['idempotency_key'],
                    );
                } elseif ($record->getRawOriginal('entry_type') === FinancialLedgerEntryType::GiftCertificateRedemption->value) {
                    app(CorrectGiftCertificateRedemption::class)->handle(
                        actor: $actor,
                        entry: $record,
                        reason: (string) $data['reason'],
                        idempotencyKey: (string) $data['idempotency_key'],
                    );
                } else {
                    app(CorrectFinancialPayment::class)->handle(
                        actor: $actor,
                        original: $record,
                        reason: (string) $data['reason'],
                        idempotencyKey: (string) $data['idempotency_key'],
                    );
                }
                $obligation = $record->obligation;
                if ($obligation instanceof FinancialObligation) {
                    self::refreshFinanceUi($action, $obligation);
                }
                Notification::make()
                    ->success()
                    ->title(__('Оплата исправлена. Исходная запись сохранена в истории.'))
                    ->send();
            });
    }

    private static function recordPaymentAction(string $name): Action
    {
        return Action::make($name)
            ->label(__('Записать оплату'))
            ->color('success')
            ->modalHeading(__('Записать оплату'))
            ->modalSubmitActionLabel(__('Записать оплату'))
            ->schema(self::paymentSchema())
            ->action(function (Action $action, Model $record, array $data): void {
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
                self::refreshFinanceUi($action, $obligation, $record instanceof Booking ? $record : null);
                Notification::make()->success()->title(__('Оплата записана. Остаток обновлён.'))->send();
            });
    }

    private static function referralCreditAction(string $name): Action
    {
        return Action::make($name)
            ->label(__('Списать бонусы'))
            ->color('warning')
            ->modalHeading(__('Списать бонусы'))
            ->modalDescription(__('Бонусы уменьшают задолженность клиента. Запись оплаты останется отдельным действием.'))
            ->modalSubmitActionLabel(__('Списать бонусы'))
            ->schema([
                TextInput::make('client_summary')
                    ->label(__('Клиент'))
                    ->default(fn (Model $record): string => self::obligation($record)?->client->full_name ?? '—')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('service_summary')
                    ->label(__('Услуга / товар'))
                    ->default(fn (Model $record): string => self::obligationName($record))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('remaining_summary')
                    ->label(__('Осталось к оплате'))
                    ->default(fn (Model $record): string => self::obligation($record) === null
                        ? '—'
                        : app(FinancePresentation::class)->settlementOutstanding(self::obligation($record)))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('available_summary')
                    ->label(__('Доступно бонусов (база)'))
                    ->default(fn (Model $record): string => self::obligation($record) === null
                        ? '—'
                        : app(FinancePresentation::class)->money(
                            app(FinancePresentation::class)->referralCreditBaseAvailable(self::obligation($record)),
                        ))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('equivalent_summary')
                    ->label(__('Эквивалент для этой задолженности'))
                    ->hidden(fn (Model $record): bool => self::referralCreditUsesBaseCurrency($record))
                    ->default(fn (Model $record): string => self::obligation($record) === null
                        ? '—'
                        : app(FinancePresentation::class)->money(
                            app(FinancePresentation::class)->referralCreditAvailable(self::obligation($record)),
                        ))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('currency_summary')
                    ->label(__('Валюта списания'))
                    ->default(fn (Model $record): string => self::obligation($record) === null
                        ? '—'
                        : (self::settlementCurrency(self::obligation($record)) ?? '—'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('amount')
                    ->label(__('Сумма бонусов к списанию'))
                    ->default(fn (Model $record): ?string => self::obligation($record) === null
                        ? null
                        : app(FinancePresentation::class)->referralCreditAmountDefault(self::obligation($record)))
                    ->placeholder(__('Введите сумму'))
                    ->inputMode('decimal')
                    ->required()
                    ->maxLength(40),
                Hidden::make('idempotency_key')
                    ->default(fn (): string => 'crm-referral-credit-'.Str::uuid()->toString()),
            ])
            ->action(function (Action $action, Model $record, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $obligation = self::obligation($record);
                abort_unless($obligation instanceof FinancialObligation, 404);

                app(ApplyReferralCreditForStaff::class)->handle(
                    actor: $actor,
                    obligation: $obligation,
                    amount: (string) ($data['amount'] ?? ''),
                    idempotencyKey: (string) $data['idempotency_key'],
                );
                self::refreshFinanceUi($action, $obligation, $record instanceof Booking ? $record : null);
                Notification::make()->success()->title(__('Бонусы списаны. Остаток обновлён.'))->send();
            });
    }

    private static function giftCertificateAction(string $name): Action
    {
        return Action::make($name)
            ->label(__('Применить сертификат'))
            ->color('warning')
            ->modalHeading(__('Применить подарочный сертификат'))
            ->modalDescription(__('Сертификат уменьшит задолженность клиента в своей валюте.'))
            ->modalSubmitActionLabel(__('Применить сертификат'))
            ->schema([
                TextInput::make('client_summary')
                    ->label(__('Клиент'))
                    ->default(fn (Model $record): string => self::obligation($record)?->client->full_name ?? '—')
                    ->disabled()
                    ->dehydrated(false),
                Select::make('certificate_id')
                    ->label(__('Сертификат'))
                    ->options(fn (Model $record): array => self::giftCertificateOptions($record))
                    ->required()
                    ->searchable(),
                TextInput::make('currency_summary')
                    ->label(__('Валюта списания'))
                    ->default(fn (Model $record): string => self::obligation($record) === null
                        ? '—'
                        : (self::settlementCurrency(self::obligation($record)) ?? '—'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('amount')
                    ->label(__('Сумма сертификата к списанию'))
                    ->default(fn (Model $record): ?string => self::giftCertificateDefaultAmount($record))
                    ->placeholder(__('Введите сумму'))
                    ->inputMode('decimal')
                    ->required()
                    ->maxLength(40),
                Hidden::make('idempotency_key')
                    ->default(fn (): string => 'crm-gift-certificate-'.Str::uuid()->toString()),
            ])
            ->action(function (Action $action, Model $record, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $obligation = self::obligation($record);
                abort_unless($obligation instanceof FinancialObligation, 404);

                app(ApplyGiftCertificateForStaff::class)->handle(
                    actor: $actor,
                    obligation: $obligation,
                    certificateId: (int) $data['certificate_id'],
                    amount: (string) ($data['amount'] ?? ''),
                    idempotencyKey: (string) $data['idempotency_key'],
                );
                self::refreshFinanceUi($action, $obligation, $record instanceof Booking ? $record : null);
                Notification::make()->success()->title(__('Сертификат применён. Остаток обновлён.'))->send();
            });
    }

    /** @return list<Component> */
    private static function paymentSchema(): array
    {
        return [
            TextInput::make('client_summary')
                ->label(__('Клиент'))
                ->default(fn (Model $record): string => self::obligation($record)?->client->full_name ?? '—')
                ->disabled()
                ->dehydrated(false),
            TextInput::make('service_summary')
                ->label(__('Услуга'))
                ->default(fn (Model $record): string => self::serviceName($record))
                ->disabled()
                ->dehydrated(false),
            TextInput::make('visit_summary')
                ->label(__('Дата визита'))
                ->default(fn (Model $record): string => app(FinancePresentation::class)->visitDate(self::obligation($record)?->booking))
                ->disabled()
                ->dehydrated(false),
            TextInput::make('remaining_summary')
                ->label(__('Осталось к оплате'))
                ->default(fn (Model $record): string => self::obligation($record) === null
                    ? '—'
                    : app(FinancePresentation::class)->settlementOutstanding(self::obligation($record)))
                ->disabled()
                ->dehydrated(false),
            TextInput::make('amount')
                ->label(__('Сумма оплаты'))
                ->default(fn (Model $record): ?string => self::obligation($record) === null
                    ? null
                    : app(FinancePresentation::class)->paymentAmountDefault(self::obligation($record)))
                ->placeholder(__('Введите сумму'))
                ->inputMode('decimal')
                ->required()
                ->maxLength(40),
            Select::make('currency')
                ->label(__('Валюта оплаты'))
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
                ->label(__('Способ оплаты'))
                ->options([
                    PaymentMethod::Cash->value => __('Наличные'),
                    PaymentMethod::BankTransfer->value => __('Банковский перевод'),
                    PaymentMethod::ManualCard->value => __('Карта в клинике'),
                    PaymentMethod::Barter->value => __('Бартер'),
                    PaymentMethod::Other->value => __('Другое'),
                ])
                ->live()
                ->required(),
            DateTimePicker::make('occurred_at')
                ->label(__('Дата и время оплаты'))
                ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                ->default(fn (): CarbonImmutable => CarbonImmutable::now(app(OrganizationContext::class)->defaultTimezone()))
                ->seconds(false)
                ->required(),
            Textarea::make('note')
                ->label(fn (Get $get): string => $get('payment_method') === PaymentMethod::Barter->value
                    ? (string) __('Что получено по бартеру')
                    : (string) __('Примечание'))
                ->placeholder(fn (Get $get): ?string => $get('payment_method') === PaymentMethod::Barter->value
                    ? (string) __('Например: рекламная интеграция, фотосъёмка, услуга специалиста.')
                    : null)
                ->helperText(fn (Get $get): ?string => $get('payment_method') === PaymentMethod::Barter->value
                    ? (string) __('Опишите, что получено взамен.')
                    : null)
                ->required(fn (Get $get): bool => $get('payment_method') === PaymentMethod::Barter->value)
                ->maxLength(2000),
            FileUpload::make('receipt')
                ->label(__('Квитанция'))
                ->helperText(__('PDF, JPG или PNG до 10 МБ.'))
                ->storeFiles(false)
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                ->maxSize(10240),
            Hidden::make('idempotency_key')
                ->default(fn (): string => 'crm-payment-'.Str::uuid()->toString()),
        ];
    }

    private static function referralCreditUsesBaseCurrency(Model $record): bool
    {
        $obligation = self::obligation($record);
        if (! $obligation instanceof FinancialObligation) {
            return false;
        }

        $baseAvailable = app(FinancePresentation::class)->referralCreditBaseAvailable($obligation);
        $settlementCurrency = self::settlementCurrency($obligation);

        return $baseAvailable !== null
            && $settlementCurrency !== null
            && $baseAvailable->currency()->value === $settlementCurrency;
    }

    /** @return array<string, string> */
    private static function giftCertificateOptions(Model $record): array
    {
        $obligation = self::obligation($record);

        return $obligation instanceof FinancialObligation
            ? app(FinancePresentation::class)->giftCertificateOptions($obligation)
            : [];
    }

    private static function giftCertificateDefaultAmount(Model $record): ?string
    {
        $obligation = self::obligation($record);
        if (! $obligation instanceof FinancialObligation) {
            return null;
        }

        $certificateId = array_key_first(self::giftCertificateOptions($record));
        if ($certificateId === null) {
            return null;
        }

        $certificate = GiftCertificate::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey((int) $certificateId)
            ->first();
        if (! $certificate instanceof GiftCertificate) {
            return null;
        }

        try {
            $available = app(GiftCertificateBalanceProjection::class)->balance($certificate);
            $outstanding = app(FinancePresentation::class)->reconciliation($obligation)?->outstanding;
            if ($outstanding === null || $outstanding->currency() !== $available->currency()) {
                return null;
            }

            return ($available->compareTo($outstanding) <= 0 ? $available : $outstanding)->toDecimalString();
        } catch (\Throwable) {
            return null;
        }
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

    private static function obligationName(Model $record): string
    {
        $obligation = self::obligation($record);

        return $obligation instanceof FinancialObligation
            ? CommerceFulfillmentPresentation::productName($obligation)
            : '—';
    }

    private static function paymentSummary(FinancialLedgerEntry $entry): string
    {
        $presentation = app(FinancePresentation::class);

        return $presentation->ledgerPaymentAmount($entry)
            .' · '.$presentation->timestamp($entry->occurred_at)
            .' · '.$presentation->paymentMethodLabel($entry);
    }

    private static function settlementCurrency(FinancialObligation $obligation): ?string
    {
        try {
            return app(FinancialReconciliationContract::class)->currency(
                $obligation->getRawOriginal('settlement_currency'),
            )->value;
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    private static function canCorrect(FinancialLedgerEntry $entry): bool
    {
        $obligation = $entry->relationLoaded('obligation')
            ? $entry->getRelation('obligation')
            : null;

        if (! $obligation instanceof FinancialObligation) {
            return false;
        }

        if ($entry->getRawOriginal('entry_type') === FinancialLedgerEntryType::ReferralCredit->value) {
            return app(RestoreReferralCredit::class)->canHandle($entry);
        }

        try {
            app(FinancialReconciliationContract::class)->validateCorrectableLedgerEntry($entry, $obligation);

            return app(FinancePresentation::class)->reconciliation($obligation) !== null;
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    private static function refreshFinanceUi(Action $action, FinancialObligation $obligation, ?Booking $booking = null): void
    {
        $obligation->refresh();
        $obligation->load(['client', 'booking.service', 'service', 'purchase.items.fulfillment']);
        app(FinancePresentation::class)->forget($obligation, $booking);
        $livewire = $action->getLivewire();

        if (! $livewire instanceof LivewireComponent) {
            return;
        }

        if (method_exists($livewire, 'flushCachedTableRecords')) {
            $livewire->flushCachedTableRecords();
        }

        $livewire->forceRender();

        $livewire->dispatch('refresh-page');
    }
}
