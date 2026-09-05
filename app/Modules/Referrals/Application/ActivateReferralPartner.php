<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;
use App\Modules\Referrals\Domain\Models\ClientReferralIdentity;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ActivateReferralPartner
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly EnsureReferralIdentity $ensureIdentity,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(Client $client, string $source, ?User $actor = null): ReferralPartnerProfile
    {
        $organization = $this->context->organization();
        abort_unless((int) $client->organization_id === (int) $organization->getKey(), 404);

        if (! in_array($source, ['portal', 'crm'], true)) {
            throw ValidationException::withMessages(['source' => 'Неизвестный источник активации партнёра.']);
        }

        if ($actor instanceof User) {
            $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        } elseif ($source !== 'portal') {
            throw ValidationException::withMessages(['source' => 'Активация из CRM требует сотрудника.']);
        }

        $identity = $this->ensureIdentity->handle($client);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($actor, $organization, $client, $identity, $source): ReferralPartnerProfile {
                    $lockedClient = Client::query()
                        ->where('organization_id', $organization->getKey())
                        ->whereKey($client->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                    $profile = ReferralPartnerProfile::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('client_id', $lockedClient->getKey())
                        ->lockForUpdate()
                        ->first();
                    $reactivated = $profile instanceof ReferralPartnerProfile && ! $profile->isActive();

                    if (! $profile instanceof ReferralPartnerProfile) {
                        $profile = new ReferralPartnerProfile;
                        $profile->forceFill([
                            'organization_id' => $organization->getKey(),
                            'client_id' => $lockedClient->getKey(),
                            'status' => ReferralPartnerStatus::Active,
                            'activated_at' => now(),
                            'activation_source' => $source,
                            'activated_by_user_id' => $actor?->getKey(),
                        ]);
                        $profile->save();
                    } elseif ($reactivated) {
                        $profile->forceFill([
                            'status' => ReferralPartnerStatus::Active,
                            'activated_at' => now(),
                            'activation_source' => $source,
                            'activated_by_user_id' => $actor?->getKey(),
                            'deactivated_at' => null,
                            'deactivated_by_user_id' => null,
                            'updated_at' => now(),
                        ])->save();
                    }

                    if ($reactivated || ! $profile->wasRecentlyCreated) {
                        $profile->refresh();
                    }

                    $defaultLink = ReferralCampaignLink::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('partner_profile_id', $profile->getKey())
                        ->where('is_default', true)
                        ->where('is_active', true)
                        ->first();

                    if (! $defaultLink instanceof ReferralCampaignLink) {
                        $this->createDefaultLink($organization->getKey(), $profile, $lockedClient, $identity, $actor);
                    }

                    if ($profile->wasRecentlyCreated || $reactivated) {
                        $this->audit->handle(
                            organization: $organization,
                            actor: $actor,
                            action: 'referral.partner.activated',
                            targetType: ReferralPartnerProfile::class,
                            targetId: (string) $profile->getKey(),
                            metadata: [
                                'client_id' => $lockedClient->getKey(),
                                'source' => $source,
                                'reactivated' => $reactivated,
                            ],
                        );
                    }

                    return $profile->refresh();
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Партнёр не был активирован.');
    }

    private function createDefaultLink(
        int $organizationId,
        ReferralPartnerProfile $profile,
        Client $client,
        ClientReferralIdentity $identity,
        ?User $actor,
    ): ReferralCampaignLink {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $link = new ReferralCampaignLink;
                $link->forceFill([
                    'organization_id' => $organizationId,
                    'partner_profile_id' => $profile->getKey(),
                    'partner_client_id' => $client->getKey(),
                    'referral_identity_id' => $identity->getKey(),
                    'public_token' => Str::random(48),
                    'name' => 'Личные рекомендации',
                    'channel' => ReferralCampaignChannel::Other,
                    'is_active' => true,
                    'is_default' => true,
                    'created_by_user_id' => $actor?->getKey(),
                ]);
                $link->save();
                $this->audit->handle(
                    organization: $profile->organization()->firstOrFail(),
                    actor: $actor,
                    action: 'referral.campaign_link.created',
                    targetType: ReferralCampaignLink::class,
                    targetId: (string) $link->getKey(),
                    metadata: [
                        'client_id' => $client->getKey(),
                        'channel' => ReferralCampaignChannel::Other->value,
                        'is_default' => true,
                    ],
                );

                return $link;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Реферальная ссылка не была создана.');
    }
}
