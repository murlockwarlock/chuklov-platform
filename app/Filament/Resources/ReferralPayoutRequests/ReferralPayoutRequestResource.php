<?php

namespace App\Filament\Resources\ReferralPayoutRequests;

use App\Filament\Resources\ReferralPayoutRequests\Pages\ListReferralPayoutRequests;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Application\TransitionReferralPayoutRequest;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends resource<ReferralPayoutRequest> */
final class ReferralPayoutRequestResource extends Resource
{
    protected static ?string $model = ReferralPayoutRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Запросы выплат';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'запрос выплаты';

    protected static ?string $pluralModelLabel = 'запросы выплат';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(FinanceAuthorization::class)->allowsView($actor);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('beneficiary.full_name')->label('Партнёр / клиент')->searchable()->wrap(),
                TextColumn::make('amount_minor')
                    ->label('Сумма')
                    ->formatStateUsing(fn (mixed $state, ReferralPayoutRequest $record): string => self::amount($record))
                    ->sortable(),
                TextColumn::make('currency')->label('Валюта')->badge(),
                TextColumn::make('requested_at')
                    ->label('Запрошено')
                    ->dateTime('d.m.Y H:i')
                    ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (mixed $state): string => self::statusLabel($state))
                    ->badge()
                    ->color(fn (mixed $state): string => self::statusColor($state)),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Одобрить')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ReferralPayoutRequest $record): bool => self::canManage() && self::status($record) === ReferralPayoutRequestStatus::Requested)
                    ->action(function (ReferralPayoutRequest $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(TransitionReferralPayoutRequest::class)->handle(
                            request: $record,
                            target: ReferralPayoutRequestStatus::Approved,
                            actor: $actor,
                            idempotencyKey: 'crm-approve',
                        );
                    }),
                Action::make('reject')
                    ->label('Отклонить')
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')->label('Причина отклонения')->required()->maxLength(2000),
                    ])
                    ->visible(fn (ReferralPayoutRequest $record): bool => self::canManage() && in_array(self::status($record), [ReferralPayoutRequestStatus::Requested, ReferralPayoutRequestStatus::Approved], true))
                    ->action(function (ReferralPayoutRequest $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(TransitionReferralPayoutRequest::class)->handle(
                            request: $record,
                            target: ReferralPayoutRequestStatus::Rejected,
                            actor: $actor,
                            idempotencyKey: 'crm-reject-'.sha1((string) ($data['reason'] ?? '')),
                            reason: (string) ($data['reason'] ?? ''),
                        );
                    }),
                Action::make('paid')
                    ->label('Отметить как выплаченную')
                    ->color('primary')
                    ->schema([
                        TextInput::make('payment_reference')->label('Платёжная пометка или ссылка')->maxLength(180),
                        Textarea::make('payment_note')->label('Комментарий о ручной выплате')->maxLength(2000),
                    ])
                    ->visible(fn (ReferralPayoutRequest $record): bool => self::canManage() && self::status($record) === ReferralPayoutRequestStatus::Approved)
                    ->action(function (ReferralPayoutRequest $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(TransitionReferralPayoutRequest::class)->handle(
                            request: $record,
                            target: ReferralPayoutRequestStatus::Paid,
                            actor: $actor,
                            idempotencyKey: 'crm-paid-'.sha1((string) ($data['payment_reference'] ?? '').'|'.(string) ($data['payment_note'] ?? '')),
                            paymentNote: isset($data['payment_note']) ? (string) $data['payment_note'] : null,
                            paymentReference: isset($data['payment_reference']) ? (string) $data['payment_reference'] : null,
                        );
                    }),
            ])
            ->defaultSort('requested_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with('beneficiary');
    }

    public static function getPages(): array
    {
        return ['index' => ListReferralPayoutRequests::route('/')];
    }

    private static function canManage(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(FinanceAuthorization::class)->allowsManage($actor);
    }

    private static function amount(ReferralPayoutRequest $record): string
    {
        try {
            $currency = CurrencyCode::from((string) $record->getRawOriginal('currency'));

            return Money::ofMinor($record->amount_minor, $currency)->toDecimalString().' '.$currency->value;
        } catch (\Throwable) {
            return '—';
        }
    }

    private static function status(ReferralPayoutRequest $record): ?ReferralPayoutRequestStatus
    {
        return ReferralPayoutRequestStatus::tryFrom((string) $record->getRawOriginal('status'));
    }

    private static function statusLabel(mixed $state): string
    {
        $status = $state instanceof ReferralPayoutRequestStatus ? $state : ReferralPayoutRequestStatus::tryFrom((string) $state);

        return $status?->label() ?? 'Неизвестно';
    }

    private static function statusColor(mixed $state): string
    {
        $status = $state instanceof ReferralPayoutRequestStatus ? $state : ReferralPayoutRequestStatus::tryFrom((string) $state);

        return match ($status) {
            ReferralPayoutRequestStatus::Paid => 'success',
            ReferralPayoutRequestStatus::Rejected, ReferralPayoutRequestStatus::Cancelled => 'danger',
            ReferralPayoutRequestStatus::Approved => 'warning',
            default => 'gray',
        };
    }
}
