<?php

namespace App\Filament\Resources\PaymentGatewayReconciliation;

use App\Filament\Resources\PaymentGatewayReconciliation\Pages\ListPaymentGatewayReconciliation;
use App\Filament\Resources\PaymentGatewayReconciliation\Tables\PaymentGatewayReconciliationTable;
use App\Filament\Support\LocalizedResource;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventStatus;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** @extends resource<PaymentGatewayEvent> */
final class PaymentGatewayReconciliationResource extends LocalizedResource
{
    protected static ?string $model = PaymentGatewayEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Требует сверки';

    protected static ?string $modelLabel = 'событие платежа';

    protected static ?string $pluralModelLabel = 'события платежей';

    protected static ?string $breadcrumb = 'Требует сверки';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 4;

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

    public static function getNavigationBadge(): ?string
    {
        if (! self::canAccess()) {
            return null;
        }

        try {
            $count = self::getEloquentQuery()->count();
        } catch (Throwable) {
            return null;
        }

        return $count > 0 ? (string) min($count, 99) : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return PaymentGatewayReconciliationTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('processing_status', PaymentGatewayEventStatus::ReconciliationRequired->value)
            ->with([
                'transaction.obligation.client',
                'transaction.obligation.booking.service',
                'transaction.obligation.service',
                'transaction.obligation.purchase.items',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentGatewayReconciliation::route('/'),
        ];
    }
}
