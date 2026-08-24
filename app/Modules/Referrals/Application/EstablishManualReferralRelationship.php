<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Domain\Enums\ReferralEstablishmentMethod;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EstablishManualReferralRelationship
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, int $referrerClientId, int $referredClientId): ReferralRelationship
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        if ($referrerClientId === $referredClientId) {
            throw ValidationException::withMessages([
                'referrer_client_id' => 'Клиент не может быть реферером сам себе.',
            ]);
        }

        $referrer = Client::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($referrerClientId)
            ->firstOrFail();
        $referred = Client::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($referredClientId)
            ->firstOrFail();

        try {
            return DB::transaction(function () use ($actor, $organization, $referrer, $referred): ReferralRelationship {
                $lockIds = [(int) $referrer->getKey(), (int) $referred->getKey()];
                sort($lockIds);
                Client::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereIn('id', $lockIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $existing = ReferralRelationship::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('referred_client_id', $referred->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof ReferralRelationship) {
                    throw ValidationException::withMessages([
                        'referrer_client_id' => 'У этого клиента уже есть реферер. Переназначение не выполняется.',
                    ]);
                }

                $firstTouch = ClientAttribution::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('client_id', $referred->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($firstTouch?->source_type === 'referral') {
                    throw ValidationException::withMessages([
                        'referrer_client_id' => 'Первая атрибуция клиента уже установлена по реферальной ссылке. Переназначение не выполняется.',
                    ]);
                }

                $relationship = new ReferralRelationship;
                $relationship->forceFill([
                    'organization_id' => $organization->getKey(),
                    'referrer_client_id' => $referrer->getKey(),
                    'referred_client_id' => $referred->getKey(),
                    'establishment_method' => ReferralEstablishmentMethod::ManualCrm,
                    'registered_at' => now(),
                ]);
                $relationship->save();
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'referral.relationship.created',
                    targetType: ReferralRelationship::class,
                    targetId: (string) $relationship->getKey(),
                    metadata: [
                        'referrer_client_id' => $referrer->getKey(),
                        'referred_client_id' => $referred->getKey(),
                        'establishment_method' => ReferralEstablishmentMethod::ManualCrm->value,
                    ],
                );

                return $relationship->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'referrer_client_id' => 'У этого клиента уже есть реферер. Переназначение не выполняется.',
            ]);
        }
    }
}
