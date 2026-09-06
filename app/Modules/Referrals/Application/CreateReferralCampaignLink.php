<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateReferralCampaignLink
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly EnsureReferralIdentity $ensureIdentity,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        Client $client,
        string $name,
        ReferralCampaignChannel $channel,
        ?User $actor = null,
    ): ReferralCampaignLink {
        $organization = $this->context->organization();
        abort_unless((int) $client->organization_id === (int) $organization->getKey(), 404);

        if ($actor instanceof User) {
            $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        }

        $name = Str::squish($name);

        if (Str::length($name) < 2 || Str::length($name) > 180) {
            throw ValidationException::withMessages(['name' => 'Название ссылки должно содержать от 2 до 180 символов.']);
        }

        $identity = $this->ensureIdentity->handle($client);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($actor, $organization, $client, $identity, $name, $channel): ReferralCampaignLink {
                    $profile = ReferralPartnerProfile::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('client_id', $client->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $profile instanceof ReferralPartnerProfile || ! $profile->isActive()) {
                        throw ValidationException::withMessages(['partner' => 'Сначала активируйте партнёрскую программу.']);
                    }

                    $link = new ReferralCampaignLink;
                    $link->forceFill([
                        'organization_id' => $organization->getKey(),
                        'partner_profile_id' => $profile->getKey(),
                        'partner_client_id' => $client->getKey(),
                        'referral_identity_id' => $identity->getKey(),
                        'public_token' => Str::random(48),
                        'name' => $name,
                        'channel' => $channel,
                        'is_active' => true,
                        'is_default' => false,
                        'created_by_user_id' => $actor?->getKey(),
                    ]);
                    $link->save();
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: 'referral.campaign_link.created',
                        targetType: ReferralCampaignLink::class,
                        targetId: (string) $link->getKey(),
                        metadata: [
                            'client_id' => $client->getKey(),
                            'channel' => $channel->value,
                            'is_default' => false,
                        ],
                    );

                    return $link->refresh();
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Реферальная ссылка не была создана.');
    }
}
