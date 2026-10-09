<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Commerce\Domain\Enums\GiftCertificateMovementType;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificateClaim;
use App\Modules\Commerce\Domain\ValueObjects\GiftCertificateTransfer;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateGiftCertificateTransfer
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly GiftCertificateBalanceProjection $balances,
        private readonly AppendGiftCertificateMovement $movements,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(Client $currentHolder, GiftCertificate $certificate): GiftCertificateTransfer
    {
        $organization = $this->context->organization();
        if ((int) $currentHolder->organization_id !== (int) $organization->getKey()
            || (int) $certificate->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The gift certificate is outside the current organization.');
        }

        return DB::transaction(function () use ($organization, $currentHolder, $certificate): GiftCertificateTransfer {
            $locked = GiftCertificate::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($certificate->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->current_holder_client_id !== (int) $currentHolder->getKey()) {
                throw new AuthorizationException('The gift certificate is not owned by this client.');
            }

            $pendingClaims = GiftCertificateClaim::query()
                ->where('organization_id', $organization->getKey())
                ->where('certificate_id', $locked->getKey())
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            if (! $this->balances->balance($locked, true)->isPositive()) {
                throw ValidationException::withMessages([
                    'certificate' => 'Сертификат с нулевым остатком нельзя передать.',
                ]);
            }

            foreach ($pendingClaims as $pendingClaim) {
                $pendingClaim->forceFill([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ])->save();
            }

            $rawToken = bin2hex(random_bytes(32));
            $claim = new GiftCertificateClaim;
            $claim->forceFill([
                'organization_id' => $organization->getKey(),
                'certificate_id' => $locked->getKey(),
                'initiated_by_client_id' => $currentHolder->getKey(),
                'claimed_client_id' => null,
                'token_hash' => hash('sha256', $rawToken),
                'status' => 'pending',
            ])->save();

            $this->movements->handle(
                certificate: $locked,
                type: GiftCertificateMovementType::Transferred,
                amountMinor: 0,
                currency: $locked->currency,
                fromHolderClientId: $currentHolder->getKey(),
                toHolderClientId: null,
                claimId: $claim->getKey(),
                redemptionId: null,
                reversesMovementId: null,
                actorUserId: null,
                idempotencyKey: 'gift_certificate.transferred:'.$organization->getKey().':'.$claim->getKey(),
                occurredAt: CarbonImmutable::now('UTC'),
            );

            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'gift_certificate.transfer.initiated',
                targetType: GiftCertificateClaim::class,
                targetId: (string) $claim->getKey(),
                metadata: [
                    'certificate_id' => $locked->getKey(),
                    'from_client_id' => $currentHolder->getKey(),
                    'currency' => $locked->currency->value,
                ],
            );

            return new GiftCertificateTransfer(
                claim: $claim->refresh(),
                url: route('gift-certificates.claim').'#token='.rawurlencode($rawToken),
                rawToken: $rawToken,
            );
        });
    }
}
