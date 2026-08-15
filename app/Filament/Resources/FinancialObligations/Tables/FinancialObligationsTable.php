<?php

namespace App\Filament\Resources\FinancialObligations\Tables;

use App\Filament\Resources\FinancialObligations\FinancialObligationResource;
use App\Models\User;
use App\Modules\Finance\Application\CorrectFinancialPayment;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\InitiateFakePayment;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Application\RecordManualPayment;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class FinancialObligationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client.full_name')->label('Клиент')->searchable()->sortable(),
                TextColumn::make('service.name')->label('Услуга')->searchable(),
                TextColumn::make('amount_summary')
                    ->label('Сумма')
                    ->state(fn (FinancialObligation $record): string => Money::ofMinor($record->amount_minor, $record->currency)->toDecimalString().' '.$record->currency->value),
                TextColumn::make('financial_status')
                    ->label('Статус оплаты')
                    ->badge()
                    ->state(fn (FinancialObligation $record): string => self::status($record)),
                TextColumn::make('outstanding_summary')
                    ->label('Осталось')
                    ->state(function (FinancialObligation $record): string {
                        $outstanding = app(ReconcileFinancialObligation::class)
                            ->handle((int) $record->organization_id, (int) $record->getKey())
                            ->outstanding;

                        return Money::ofMinor($outstanding->minorUnits(), $outstanding->currency())->toDecimalString()
                            .' '.$outstanding->currency()->value;
                    }),
                TextColumn::make('created_at')->label('Создано')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->recordActions([
                ViewAction::make()->label('Открыть'),
                self::recordPaymentAction(),
                self::correctionAction(),
                self::fakeGatewayAction(),
            ]);
    }

    private static function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')
            ->label('Записать оплату')
            ->color('success')
            ->visible(fn (FinancialObligation $record): bool => FinancialObligationResource::canManage()
                && app(ReconcileFinancialObligation::class)->handle((int) $record->organization_id, (int) $record->getKey())->outstanding->isPositive())
            ->schema([
                TextInput::make('amount')->label('Сумма')->required()->maxLength(40),
                Select::make('currency')
                    ->label('Валюта оплаты')
                    ->options(fn (): array => self::currencyOptions())
                    ->required(),
                Select::make('payment_method')
                    ->label('Способ оплаты')
                    ->options([
                        PaymentMethod::Cash->value => 'Наличные',
                        PaymentMethod::BankTransfer->value => 'Перевод',
                        PaymentMethod::ManualCard->value => 'Карта в клинике',
                        PaymentMethod::Other->value => 'Другое',
                    ])
                    ->required(),
                DateTimePicker::make('occurred_at')->label('Время оплаты')->seconds(false)->default(now())->required(),
                Textarea::make('note')->label('Примечание')->maxLength(2000),
                FileUpload::make('receipt')
                    ->label('Квитанция')
                    ->storeFiles(false)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(10240),
                Hidden::make('idempotency_key')->default(fn (): string => 'crm-payment-'.Str::uuid()->toString()),
            ])
            ->action(function (FinancialObligation $record, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                app(RecordManualPayment::class)->handle(
                    actor: $actor,
                    obligation: $record,
                    amount: (string) $data['amount'],
                    currency: (string) $data['currency'],
                    paymentMethod: (string) $data['payment_method'],
                    occurredAt: $data['occurred_at'],
                    note: isset($data['note']) ? (string) $data['note'] : null,
                    receipt: $data['receipt'] ?? null,
                    idempotencyKey: (string) $data['idempotency_key'],
                );
                Notification::make()->success()->title('Оплата записана')->send();
            });
    }

    private static function correctionAction(): Action
    {
        return Action::make('correctPayment')
            ->label('Исправить оплату')
            ->color('warning')
            ->visible(fn (): bool => FinancialObligationResource::canManage())
            ->schema([
                Select::make('ledger_entry_id')
                    ->label('Запись оплаты')
                    ->options(fn (FinancialObligation $record): array => $record->ledgerEntries
                        ->filter(fn (FinancialLedgerEntry $entry): bool => in_array($entry->entry_type->value, ['manual_payment', 'fake_gateway_settlement'], true)
                            && ! FinancialLedgerEntry::query()
                                ->where('organization_id', $record->organization_id)
                                ->where('corrects_ledger_entry_id', $entry->getKey())
                                ->exists())
                        ->mapWithKeys(fn (FinancialLedgerEntry $entry): array => [
                            $entry->getKey() => Money::ofMinor($entry->amount_minor, $entry->currency)->toDecimalString().' '.$entry->currency->value.' · '.$entry->occurred_at->format('d.m.Y H:i'),
                        ])
                        ->all())
                    ->required(),
                Textarea::make('reason')->label('Причина исправления')->required()->maxLength(500),
                Hidden::make('idempotency_key')->default(fn (): string => 'crm-correction-'.Str::uuid()->toString()),
            ])
            ->action(function (FinancialObligation $record, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $entry = FinancialLedgerEntry::query()
                    ->where('organization_id', app(OrganizationContext::class)->id())
                    ->where('obligation_id', $record->getKey())
                    ->whereKey((int) $data['ledger_entry_id'])
                    ->firstOrFail();
                app(CorrectFinancialPayment::class)->handle(
                    actor: $actor,
                    original: $entry,
                    reason: (string) $data['reason'],
                    idempotencyKey: (string) $data['idempotency_key'],
                );
                Notification::make()->success()->title('Исправление добавлено в историю')->send();
            });
    }

    private static function fakeGatewayAction(): Action
    {
        return Action::make('fakeGateway')
            ->label('Создать тестовую оплату')
            ->color('gray')
            ->visible(fn (FinancialObligation $record): bool => FinancialObligationResource::canManage()
                && app(ReconcileFinancialObligation::class)->handle((int) $record->organization_id, (int) $record->getKey())->outstanding->isPositive())
            ->schema([
                Hidden::make('idempotency_key')->default(fn (): string => 'crm-fake-'.Str::uuid()->toString()),
            ])
            ->action(function (FinancialObligation $record, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                app(InitiateFakePayment::class)->handle($actor, $record, (string) $data['idempotency_key']);
                Notification::make()->success()->title('Тестовая оплата создана без реального списания')->send();
            });
    }

    private static function status(FinancialObligation $record): string
    {
        return match (app(ReconcileFinancialObligation::class)->handle((int) $record->organization_id, (int) $record->getKey())->status->value) {
            'settled' => 'Оплачено',
            'partially_paid' => 'Частично',
            default => 'К оплате',
        };
    }

    /** @return array<string, string> */
    private static function currencyOptions(): array
    {
        try {
            return collect(app(CurrencyConfigurationService::class)->allowedCurrencies(app(OrganizationContext::class)->id()))
                ->mapWithKeys(fn ($currency): array => [$currency->value => $currency->value])
                ->all();
        } catch (ModelNotFoundException) {
            return [];
        }
    }
}
