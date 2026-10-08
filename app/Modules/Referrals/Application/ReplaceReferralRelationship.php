<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
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

final class ReplaceReferralRelationship
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        int $referrerClientId,
        int $referredClientId,
        ?string $reason = null,
    ): ReferralRelationship {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageReferralRelationships);

        if ($referrerClientId === $referredClientId) {
            throw ValidationException::withMessages([
                'referrer_client_id' => 'Клиент не может быть пригласившим сам себе.',
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
        $reason = trim((string) $reason);

        try {
            return DB::transaction(function () use ($actor, $organization, $referrer, $referred, $reason): ReferralRelationship {
                $lockIds = [(int) $referrer->getKey(), (int) $referred->getKey()];
                sort($lockIds);
                Client::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereIn('id', $lockIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $current = ReferralRelationship::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('referred_client_id', $referred->getKey())
                    ->whereNull('superseded_at')
                    ->lockForUpdate()
                    ->first();

                if (! $current instanceof ReferralRelationship) {
                    throw ValidationException::withMessages([
                        'referrer_client_id' => 'У клиента пока нет реферальной связи для замены.',
                    ]);
                }

                if ((int) $current->referrer_client_id === (int) $referrer->getKey()) {
                    throw ValidationException::withMessages([
                        'referrer_client_id' => 'Этот клиент уже указан пригласившим.',
                    ]);
                }

                $current->forceFill([
                    'superseded_at' => now(),
                ])->save();

                $replacement = new ReferralRelationship;
                $replacement->forceFill([
                    'organization_id' => $organization->getKey(),
                    'referrer_client_id' => $referrer->getKey(),
                    'referred_client_id' => $referred->getKey(),
                    'establishment_method' => ReferralEstablishmentMethod::ManualCrm,
                    'registered_at' => now(),
                ]);
                $replacement->save();

                $current->forceFill([
                    'superseded_by_relationship_id' => $replacement->getKey(),
                ])->save();

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'referral.relationship.replaced',
                    targetType: ReferralRelationship::class,
                    targetId: (string) $replacement->getKey(),
                    metadata: [
                        'old_referrer_client_id' => $current->referrer_client_id,
                        'new_referrer_client_id' => $replacement->referrer_client_id,
                        'referred_client_id' => $replacement->referred_client_id,
                        'source' => 'crm_admin',
                        'reason_present' => $reason !== '',
                    ],
                );

                return $replacement->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'referrer_client_id' => 'Реферальная связь уже была изменена. Обновите страницу и повторите действие.',
            ]);
        }
    }
}
