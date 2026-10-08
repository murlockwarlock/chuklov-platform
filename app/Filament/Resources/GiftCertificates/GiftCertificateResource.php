<?php

namespace App\Filament\Resources\GiftCertificates;

use App\Filament\Resources\FinancialObligations\FinancialObligationResource;
use App\Filament\Resources\GiftCertificates\Pages\ListGiftCertificates;
use App\Filament\Resources\GiftCertificates\Pages\ViewGiftCertificate;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\LocalizedResource;
use App\Models\User;
use App\Modules\Commerce\Application\GiftCertificateBalanceProjection;
use App\Modules\Commerce\Domain\Enums\GiftCertificateMovementType;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificateMovement;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class GiftCertificateResource extends LocalizedResource
{
    protected static ?string $model = GiftCertificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Сертификаты';

    protected static ?string $modelLabel = 'подарочный сертификат';

    protected static ?string $pluralModelLabel = 'подарочные сертификаты';

    protected static ?string $breadcrumb = 'Сертификаты';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(FinanceAuthorization::class)->allowsView($actor);
    }

    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof GiftCertificate
            && (int) $record->organization_id === app(OrganizationContext::class)->id()
            && self::canAccess();
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

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('current_holder.full_name')
                    ->label(__('Владелец'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->url(fn (GiftCertificate $record): ?string => CrmEntityLinks::clientUrl($record->currentHolder))
                    ->color(fn (GiftCertificate $record): ?string => CrmEntityLinks::clientUrl($record->currentHolder) === null ? null : 'primary'),
                TextColumn::make('purchaser.full_name')
                    ->label(__('Покупатель'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->url(fn (GiftCertificate $record): ?string => CrmEntityLinks::clientUrl($record->purchaser))
                    ->color(fn (GiftCertificate $record): ?string => CrmEntityLinks::clientUrl($record->purchaser) === null ? null : 'primary'),
                TextColumn::make('original_amount_minor')
                    ->label(__('Номинал'))
                    ->state(fn (GiftCertificate $record): string => self::amount($record->original_amount_minor, $record->currency))
                    ->sortable(),
                TextColumn::make('balance_summary')
                    ->label(__('Остаток'))
                    ->state(fn (GiftCertificate $record): string => self::balance($record))
                    ->sortable(false),
                TextColumn::make('issued_at')
                    ->label(__('Выпущен'))
                    ->dateTime('d.m.Y H:i')
                    ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()->label(__('Открыть')),
            ])
            ->defaultSort('issued_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Сертификат'))
                ->schema([
                    TextEntry::make('current_holder.full_name')
                        ->label(__('Владелец'))
                        ->url(fn (GiftCertificate $record): ?string => CrmEntityLinks::clientUrl($record->currentHolder))
                        ->color(fn (GiftCertificate $record): ?string => CrmEntityLinks::clientUrl($record->currentHolder) === null ? null : 'primary')
                        ->placeholder(__('Не назначен'))
                        ->wrap(),
                    TextEntry::make('purchaser.full_name')
                        ->label(__('Покупатель'))
                        ->url(fn (GiftCertificate $record): ?string => CrmEntityLinks::clientUrl($record->purchaser))
                        ->color(fn (GiftCertificate $record): ?string => CrmEntityLinks::clientUrl($record->purchaser) === null ? null : 'primary')
                        ->wrap(),
                    TextEntry::make('original_amount')
                        ->label(__('Номинал'))
                        ->state(fn (GiftCertificate $record): string => self::amount($record->original_amount_minor, $record->currency)),
                    TextEntry::make('balance')
                        ->label(__('Текущий остаток'))
                        ->state(fn (GiftCertificate $record): string => self::balance($record)),
                    TextEntry::make('issued_at')
                        ->label(__('Дата выпуска'))
                        ->dateTime('d.m.Y H:i')
                        ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone()),
                    TextEntry::make('source_purchase')
                        ->label(__('Исходная покупка'))
                        ->state(fn (GiftCertificate $record): string => self::purchaseLabel($record))
                        ->url(fn (GiftCertificate $record): ?string => self::purchaseUrl($record))
                        ->color(fn (GiftCertificate $record): ?string => self::purchaseUrl($record) === null ? null : 'primary')
                        ->wrap(),
                ])
                ->columns(2),
            Section::make(__('История операций'))
                ->schema([
                    RepeatableEntry::make('history')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('operation')->label(__('Операция'))->weight('semibold')->wrap(),
                            TextEntry::make('amount')->label(__('Сумма')),
                            TextEntry::make('occurred_at')->label(__('Когда')),
                            TextEntry::make('actor')->label(__('Кто изменил'))->wrap(),
                        ])
                        ->columns(2)
                        ->state(fn (GiftCertificate $record): array => $record->movements
                            ->sortBy(fn (GiftCertificateMovement $movement): string => $movement->occurred_at->toIso8601String().'|'.$movement->getKey())
                            ->map(fn (GiftCertificateMovement $movement): array => [
                                'operation' => self::movementLabel($movement->movement_type),
                                'amount' => self::amount($movement->amount_minor, $movement->currency),
                                'occurred_at' => $movement->occurred_at->setTimezone(app(OrganizationContext::class)->defaultTimezone())->format('d.m.Y H:i'),
                                'actor' => ($actor = $movement->getRelationValue('actor')) instanceof User
                                    ? $actor->name
                                    : __('Система'),
                            ])
                            ->values()
                            ->all())
                        ->placeholder(__('Операций пока нет'))
                        ->columnSpanFull(),
                ])
                ->compact()
                ->columnSpanFull(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with([
                'currentHolder',
                'purchaser',
                'purchaseItem',
                'purchase.obligation',
                'movements.actor',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGiftCertificates::route('/'),
            'view' => ViewGiftCertificate::route('/{record}'),
        ];
    }

    private static function balance(GiftCertificate $record): string
    {
        try {
            return self::amount(app(GiftCertificateBalanceProjection::class)->balance($record)->minorUnits(), $record->currency);
        } catch (Throwable) {
            return __('Недоступен');
        }
    }

    private static function amount(int $minor, mixed $currency): string
    {
        $currency = $currency instanceof CurrencyCode ? $currency : CurrencyCode::tryFrom((string) $currency);
        if ($currency === null) {
            return '—';
        }

        try {
            return Money::ofMinor($minor, $currency)->toDecimalString().' '.$currency->value;
        } catch (Throwable) {
            return '—';
        }
    }

    private static function purchaseLabel(GiftCertificate $record): string
    {
        $snapshot = $record->purchaseItem?->product_snapshot;
        $name = is_array($snapshot) && is_string($snapshot['name'] ?? null)
            ? $snapshot['name']
            : __('Подарочный сертификат');

        return $name.' · #'.$record->purchase_id;
    }

    private static function purchaseUrl(GiftCertificate $record): ?string
    {
        $obligation = $record->purchase?->obligation;

        return $obligation === null
            ? null
            : FinancialObligationResource::getUrl('view', ['record' => $obligation->getKey()]);
    }

    private static function movementLabel(mixed $movement): string
    {
        $movement = $movement instanceof GiftCertificateMovementType
            ? $movement
            : GiftCertificateMovementType::tryFrom((string) $movement);

        return match ($movement) {
            GiftCertificateMovementType::Issued => __('Выпущен'),
            GiftCertificateMovementType::Transferred => __('Создана ссылка подарка'),
            GiftCertificateMovementType::Claimed => __('Передан получателю'),
            GiftCertificateMovementType::Redeemed => __('Применён к оплате'),
            GiftCertificateMovementType::RedemptionReversed => __('Возвращён после исправления'),
            default => __('Операция сертификата'),
        };
    }
}
