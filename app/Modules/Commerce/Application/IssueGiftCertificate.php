<?php

namespace App\Modules\Commerce\Application;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\GiftCertificateMovementType;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\PurchaseFulfillment;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IssueGiftCertificate
{
    public function __construct(
        private readonly AppendGiftCertificateMovement $movements,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(PurchaseFulfillment $fulfillment, ?User $actor = null): GiftCertificate
    {
        $movements = $this->movements;
        $audit = $this->audit;

        return DB::transaction(function () use ($fulfillment, $actor, $movements, $audit): GiftCertificate {
            $lockedFulfillment = PurchaseFulfillment::query()
                ->where('organization_id', $fulfillment->organization_id)
                ->whereKey($fulfillment->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedFulfillment instanceof PurchaseFulfillment) {
                throw (new ModelNotFoundException)->setModel(PurchaseFulfillment::class, [$fulfillment->getKey()]);
            }

            $item = $lockedFulfillment->item()->lockForUpdate()->firstOrFail();
            $purchase = $item->purchase()->lockForUpdate()->firstOrFail();
            $organization = Organization::query()
                ->whereKey($purchase->organization_id)
                ->firstOrFail();
            $existing = GiftCertificate::query()
                ->where('organization_id', $organization->getKey())
                ->where('purchase_item_id', $item->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof GiftCertificate) {
                return $existing;
            }

            if ($purchase->status !== PurchaseStatus::Paid) {
                throw ValidationException::withMessages([
                    'purchase' => 'Сертификат можно выпустить только после полной оплаты покупки.',
                ]);
            }

            $snapshot = $item->getAttribute('product_snapshot');
            if (! is_array($snapshot) || ($snapshot['kind'] ?? null) !== 'gift_certificate') {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Эта выдача не является подарочным сертификатом.',
                ]);
            }

            $client = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($purchase->client_id)
                ->firstOrFail();
            $currency = CurrencyCode::tryFrom((string) $item->getRawOriginal('currency'));
            if ($currency === null || (int) $item->amount_minor <= 0) {
                throw ValidationException::withMessages([
                    'purchase' => 'Номинал сертификата указан неверно.',
                ]);
            }

            $certificate = new GiftCertificate;
            $certificate->forceFill([
                'organization_id' => $organization->getKey(),
                'purchase_id' => $purchase->getKey(),
                'purchase_item_id' => $item->getKey(),
                'purchase_fulfillment_id' => $lockedFulfillment->getKey(),
                'purchaser_client_id' => $client->getKey(),
                'current_holder_client_id' => $client->getKey(),
                'original_amount_minor' => (int) $item->amount_minor,
                'currency' => $currency->value,
                'issued_at' => CarbonImmutable::now('UTC'),
            ])->save();

            $movements->handle(
                certificate: $certificate,
                type: GiftCertificateMovementType::Issued,
                amountMinor: (int) $item->amount_minor,
                currency: $currency,
                fromHolderClientId: null,
                toHolderClientId: $client->getKey(),
                claimId: null,
                redemptionId: null,
                reversesMovementId: null,
                actorUserId: $actor?->getKey(),
                idempotencyKey: 'gift_certificate.issued:'.$organization->getKey().':'.$item->getKey(),
                occurredAt: CarbonImmutable::now('UTC'),
            );

            $audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'gift_certificate.issued',
                targetType: GiftCertificate::class,
                targetId: (string) $certificate->getKey(),
                metadata: [
                    'purchase_item_id' => $item->getKey(),
                    'amount_minor' => (int) $item->amount_minor,
                    'currency' => $currency->value,
                ],
            );

            return $certificate->refresh();
        });
    }
}
