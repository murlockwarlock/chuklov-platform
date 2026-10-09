<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Commerce\Domain\Enums\GiftCertificateMovementType;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificateClaim;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClaimGiftCertificate
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly GiftCertificateBalanceProjection $balances,
        private readonly AppendGiftCertificateMovement $movements,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(Client $recipient, string $rawToken): GiftCertificate
    {
        $organization = $this->context->organization();
        if ((int) $recipient->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The recipient is outside the current organization.');
        }

        $rawToken = trim($rawToken);
        if (preg_match('/^[a-f0-9]{64}$/', $rawToken) !== 1) {
            throw ValidationException::withMessages(['token' => 'Ссылка на сертификат недействительна или уже использована.']);
        }

        $tokenHash = hash('sha256', $rawToken);
        $claimId = GiftCertificateClaim::query()
            ->where('organization_id', $organization->getKey())
            ->where('token_hash', $tokenHash)
            ->value('id');
        if ($claimId === null) {
            throw ValidationException::withMessages(['token' => 'Ссылка на сертификат недействительна или уже использована.']);
        }

        $certificateId = GiftCertificateClaim::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($claimId)
            ->where('token_hash', $tokenHash)
            ->value('certificate_id');
        if ($certificateId === null) {
            throw ValidationException::withMessages(['token' => 'Ссылка на сертификат недействительна или уже использована.']);
        }

        return DB::transaction(function () use ($organization, $recipient, $tokenHash, $claimId, $certificateId): GiftCertificate {
            $certificate = GiftCertificate::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($certificateId)
                ->lockForUpdate()
                ->first();
            if (! $certificate instanceof GiftCertificate) {
                throw ValidationException::withMessages(['token' => 'Ссылка на сертификат недействительна или уже использована.']);
            }

            $claim = GiftCertificateClaim::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($claimId)
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();
            if (! $claim instanceof GiftCertificateClaim
                || $claim->status !== 'pending'
                || (int) $claim->certificate_id !== (int) $certificate->getKey()
                || (int) $certificate->current_holder_client_id !== (int) $claim->initiated_by_client_id
                || ! $this->balances->balance($certificate, true)->isPositive()) {
                throw ValidationException::withMessages(['token' => 'Ссылка на сертификат недействительна или уже использована.']);
            }
            if ((int) $certificate->current_holder_client_id === (int) $recipient->getKey()
                || (int) $claim->initiated_by_client_id === (int) $recipient->getKey()) {
                throw ValidationException::withMessages([
                    'token' => 'Текущий владелец не может получить этот сертификат повторно.',
                ]);
            }

            $now = CarbonImmutable::now('UTC');
            $claim->forceFill([
                'status' => 'claimed',
                'claimed_client_id' => $recipient->getKey(),
                'claimed_at' => $now,
                'updated_at' => $now,
            ])->save();
            $certificate->forceFill([
                'current_holder_client_id' => $recipient->getKey(),
            ])->save();

            $this->movements->handle(
                certificate: $certificate,
                type: GiftCertificateMovementType::Claimed,
                amountMinor: 0,
                currency: $certificate->currency,
                fromHolderClientId: null,
                toHolderClientId: $recipient->getKey(),
                claimId: $claim->getKey(),
                redemptionId: null,
                reversesMovementId: null,
                actorUserId: null,
                idempotencyKey: 'gift_certificate.claimed:'.$organization->getKey().':'.$claim->getKey(),
                occurredAt: $now,
            );

            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'gift_certificate.claimed',
                targetType: GiftCertificate::class,
                targetId: (string) $certificate->getKey(),
                metadata: [
                    'claim_id' => $claim->getKey(),
                    'recipient_client_id' => $recipient->getKey(),
                ],
            );

            return $certificate->refresh();
        });
    }
}
