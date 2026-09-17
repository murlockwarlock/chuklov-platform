<?php

namespace App\Modules\Commerce\Application;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Models\FulfillmentEvent;
use App\Modules\Commerce\Domain\Models\PurchaseFulfillment;
use App\Modules\Finance\Application\FinanceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FulfillManualPurchaseItem
{
    public function __construct(private readonly FinanceAuthorization $authorization) {}

    public function handle(User $actor, PurchaseFulfillment $fulfillment): PurchaseFulfillment
    {
        $organization = $this->authorization->authorizeManage($actor);
        if ((int) $fulfillment->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The fulfillment is outside the current organization.');
        }

        return DB::transaction(function () use ($actor, $organization, $fulfillment): PurchaseFulfillment {
            $locked = PurchaseFulfillment::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($fulfillment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $purchase = $locked->item()->firstOrFail()->purchase()->lockForUpdate()->firstOrFail();
            if ($purchase->status->value !== 'paid') {
                throw ValidationException::withMessages(['fulfillment' => 'Выдать доступ можно только после подтверждённой оплаты.']);
            }
            if ($locked->provider_type !== 'manual') {
                throw ValidationException::withMessages(['fulfillment' => 'Для этого товара используется автоматическая выдача.']);
            }
            if ($locked->status === CommerceFulfillmentStatus::Fulfilled) {
                return $locked;
            }

            $from = $locked->status->value;
            $locked->forceFill([
                'status' => CommerceFulfillmentStatus::Fulfilled->value,
                'attempts' => (int) $locked->attempts + 1,
                'last_error' => null,
                'fulfilled_at' => now(),
            ])->save();
            $event = new FulfillmentEvent;
            $event->forceFill([
                'organization_id' => $organization->getKey(),
                'fulfillment_id' => $locked->getKey(),
                'from_status' => $from,
                'to_status' => CommerceFulfillmentStatus::Fulfilled->value,
                'actor_user_id' => $actor->getKey(),
                'metadata' => ['source' => 'manual_crm_action'],
            ])->save();

            return $locked->refresh();
        });
    }
}
