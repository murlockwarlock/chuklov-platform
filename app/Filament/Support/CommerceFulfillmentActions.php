<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Modules\Commerce\Application\FulfillManualPurchaseItem;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

final class CommerceFulfillmentActions
{
    public static function forObligation(): Action
    {
        return Action::make('fulfillPurchase')
            ->label('Доступ выдан')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Подтвердить выдачу доступа')
            ->modalDescription('Отметьте доступ как выданный только после ручной проверки внешней системы.')
            ->visible(fn (FinancialObligation $record): bool => self::canFulfill($record))
            ->action(function (FinancialObligation $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $fulfillment = CommerceFulfillmentPresentation::manualFulfillment($record);
                abort_unless($fulfillment !== null, 422);
                app(FulfillManualPurchaseItem::class)->handle($actor, $fulfillment);
                Notification::make()
                    ->success()
                    ->title('Доступ отмечен как выданный.')
                    ->send();
            });
    }

    private static function canFulfill(FinancialObligation $record): bool
    {
        $actor = auth()->user();
        $purchase = $record->purchase;
        $fulfillment = CommerceFulfillmentPresentation::manualFulfillment($record);

        return $actor instanceof User
            && app(FinanceAuthorization::class)->allowsManage($actor)
            && $purchase?->status === PurchaseStatus::Paid
            && $fulfillment !== null;
    }
}
