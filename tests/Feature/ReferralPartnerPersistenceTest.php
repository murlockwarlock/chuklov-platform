<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;
use App\Modules\Referrals\Domain\Models\ClientReferralIdentity;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReferralPartnerPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_partner_profile_exists_per_organization_client(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();

        ReferralPartnerProfile::forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'status' => ReferralPartnerStatus::Active,
            'activation_source' => 'portal',
            'activated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        ReferralPartnerProfile::forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'status' => ReferralPartnerStatus::Active,
            'activation_source' => 'crm',
            'activated_at' => now(),
        ]);
    }

    public function test_campaign_link_preserves_typed_channel_and_historical_disabled_state(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $identity = ClientReferralIdentity::forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'public_code' => str_repeat('A', 32),
        ]);
        $profile = ReferralPartnerProfile::forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'status' => ReferralPartnerStatus::Active,
            'activation_source' => 'portal',
            'activated_at' => now(),
        ]);
        $link = ReferralCampaignLink::forceCreate([
            'organization_id' => $organization->getKey(),
            'partner_profile_id' => $profile->getKey(),
            'partner_client_id' => $client->getKey(),
            'referral_identity_id' => $identity->getKey(),
            'public_token' => str_repeat('B', 32),
            'name' => 'Instagram — шапка профиля',
            'channel' => ReferralCampaignChannel::Instagram,
            'is_active' => true,
            'is_default' => false,
        ]);

        $link->forceFill(['is_active' => false, 'disabled_at' => now()])->save();
        $historical = $link->fresh();

        self::assertSame(ReferralCampaignChannel::Instagram, $historical->channel);
        self::assertFalse($historical->is_active);
        self::assertNotNull($historical->disabled_at);
    }

    public function test_campaign_tracking_token_is_unique_within_an_organization(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $identity = ClientReferralIdentity::forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'public_code' => str_repeat('C', 32),
        ]);
        $profile = ReferralPartnerProfile::forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'status' => ReferralPartnerStatus::Active,
            'activation_source' => 'portal',
            'activated_at' => now(),
        ]);
        $attributes = [
            'organization_id' => $organization->getKey(),
            'partner_profile_id' => $profile->getKey(),
            'partner_client_id' => $client->getKey(),
            'referral_identity_id' => $identity->getKey(),
            'public_token' => str_repeat('D', 32),
            'name' => 'Telegram — канал',
            'channel' => ReferralCampaignChannel::Telegram,
            'is_active' => true,
            'is_default' => false,
        ];
        ReferralCampaignLink::forceCreate($attributes);

        $this->expectException(QueryException::class);
        ReferralCampaignLink::forceCreate($attributes);
    }
}
