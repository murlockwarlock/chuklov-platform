<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Models\ClientReferralIdentity;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EnsureReferralIdentity
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(Client $client): ClientReferralIdentity
    {
        $organization = $this->context->organization();

        abort_unless((int) $client->organization_id === (int) $organization->getKey(), 404);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($organization, $client): ClientReferralIdentity {
                    $lockedClient = Client::query()
                        ->where('organization_id', $organization->getKey())
                        ->whereKey($client->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                    $existing = ClientReferralIdentity::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('client_id', $lockedClient->getKey())
                        ->first();

                    if ($existing instanceof ClientReferralIdentity) {
                        return $existing;
                    }

                    $identity = new ClientReferralIdentity;
                    $identity->forceFill([
                        'organization_id' => $organization->getKey(),
                        'client_id' => $lockedClient->getKey(),
                        'public_code' => $this->token(),
                    ]);
                    $identity->save();
                    $this->audit->handle(
                        organization: $organization,
                        actor: null,
                        action: 'referral.identity.created',
                        targetType: ClientReferralIdentity::class,
                        targetId: (string) $identity->getKey(),
                        metadata: ['client_id' => $lockedClient->getKey()],
                    );

                    return $identity->refresh();
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('A referral identity could not be created.');
    }

    private function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
