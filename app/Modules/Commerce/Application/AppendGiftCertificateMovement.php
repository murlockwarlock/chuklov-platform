<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Commerce\Domain\Enums\GiftCertificateMovementType;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificateMovement;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class AppendGiftCertificateMovement
{
    public function handle(
        GiftCertificate $certificate,
        GiftCertificateMovementType $type,
        int $amountMinor,
        CurrencyCode $currency,
        ?int $fromHolderClientId,
        ?int $toHolderClientId,
        ?int $claimId,
        ?int $redemptionId,
        ?int $reversesMovementId,
        ?int $actorUserId,
        string $idempotencyKey,
        CarbonImmutable $occurredAt,
    ): GiftCertificateMovement {
        if ((int) $certificate->organization_id <= 0 || $amountMinor < 0 || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('The gift certificate movement is invalid.');
        }

        if ($currency->value !== $certificate->getRawOriginal('currency')) {
            throw new InvalidArgumentException('The gift certificate movement currency is invalid.');
        }

        $movement = new GiftCertificateMovement;
        $movement->forceFill([
            'organization_id' => $certificate->organization_id,
            'certificate_id' => $certificate->getKey(),
            'movement_type' => $type->value,
            'amount_minor' => $amountMinor,
            'currency' => $currency->value,
            'from_holder_client_id' => $fromHolderClientId,
            'to_holder_client_id' => $toHolderClientId,
            'claim_id' => $claimId,
            'redemption_id' => $redemptionId,
            'reverses_movement_id' => $reversesMovementId,
            'actor_user_id' => $actorUserId,
            'idempotency_key' => trim($idempotencyKey),
            'occurred_at' => $occurredAt,
        ])->save();

        return $movement->refresh();
    }
}
