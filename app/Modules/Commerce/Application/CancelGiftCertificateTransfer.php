<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificateClaim;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelGiftCertificateTransfer
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(Client $currentHolder, GiftCertificate|int $certificate): GiftCertificateClaim
    {
        $organization = $this->context->organization();
        if ((int) $currentHolder->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $certificateId = $certificate instanceof GiftCertificate ? (int) $certificate->getKey() : $certificate;

        return DB::transaction(function () use ($organization, $currentHolder, $certificateId): GiftCertificateClaim {
            $lockedCertificate = GiftCertificate::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($certificateId)
                ->lockForUpdate()
                ->first();
            if (! $lockedCertificate instanceof GiftCertificate) {
                throw new AuthorizationException('The gift certificate is outside the current organization.');
            }

            if ((int) $lockedCertificate->current_holder_client_id !== (int) $currentHolder->getKey()) {
                throw new AuthorizationException('The gift certificate is not owned by this client.');
            }

            $claim = GiftCertificateClaim::query()
                ->where('organization_id', $organization->getKey())
                ->where('certificate_id', $lockedCertificate->getKey())
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if (! $claim instanceof GiftCertificateClaim) {
                throw ValidationException::withMessages([
                    'certificate' => 'Для этого сертификата нет активной передачи.',
                ]);
            }

            $now = now();
            $claim->forceFill([
                'status' => 'revoked',
                'revoked_at' => $now,
                'updated_at' => $now,
            ])->save();

            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'gift_certificate.transfer.cancelled',
                targetType: GiftCertificateClaim::class,
                targetId: (string) $claim->getKey(),
                metadata: [
                    'certificate_id' => $lockedCertificate->getKey(),
                    'from_client_id' => $currentHolder->getKey(),
                    'currency' => $lockedCertificate->currency->value,
                ],
            );

            return $claim->refresh();
        });
    }
}
