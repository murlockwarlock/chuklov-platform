<?php

namespace Tests\Feature;

use App\Modules\Attribution\Application\CapturePreAuthAttribution;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Identity\Application\RegisterClientAcquisition;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\CreateReferralCampaignLink;
use App\Modules\Referrals\Application\DeactivateReferralCampaignLink;
use App\Modules\Referrals\Application\FinalizeClientAcquisition;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReferralCampaignAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_registration_persists_originating_link_once(): void
    {
        $organization = $this->organization();
        $partner = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $link = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Instagram — шапка профиля',
            channel: ReferralCampaignChannel::Instagram,
        );
        $sessionId = 'campaign-registration-session';
        app(CapturePreAuthAttribution::class)->handle(
            sessionId: $sessionId,
            input: ['referral_code' => $link->public_token],
            captureChannel: 'portal',
            captureContext: 'referral_route',
        );
        $referred = Client::factory()->forOrganization($organization)->create(['lead_source' => null]);
        app(RegisterClientAcquisition::class)->handle($organization, $referred, $sessionId);

        app(FinalizeClientAcquisition::class)->handle($referred, $sessionId);
        app(FinalizeClientAcquisition::class)->handle($referred, $sessionId);

        $relationship = ReferralRelationship::query()->where('referred_client_id', $referred->getKey())->sole();
        self::assertSame($partner->getKey(), $relationship->referrer_client_id);
        self::assertSame($link->getKey(), $relationship->referral_campaign_link_id);
        self::assertSame($link->public_token, ClientAttribution::query()->where('client_id', $referred->getKey())->value('referral_code'));
    }

    public function test_disabled_campaign_link_cannot_create_new_referral_attribution(): void
    {
        $organization = $this->organization();
        $partner = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $link = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Telegram — канал',
            channel: ReferralCampaignChannel::Telegram,
        );
        app(DeactivateReferralCampaignLink::class)->handle($link, $partner);
        $sessionId = 'disabled-campaign-session';
        app(CapturePreAuthAttribution::class)->handle(
            sessionId: $sessionId,
            input: ['referral_code' => $link->public_token],
            captureChannel: 'portal',
            captureContext: 'referral_route',
        );
        $referred = Client::factory()->forOrganization($organization)->create(['lead_source' => null]);
        app(RegisterClientAcquisition::class)->handle($organization, $referred, $sessionId);

        app(FinalizeClientAcquisition::class)->handle($referred, $sessionId);

        self::assertDatabaseMissing('referral_relationships', ['referred_client_id' => $referred->getKey()]);
        self::assertDatabaseMissing('client_attributions', ['client_id' => $referred->getKey()]);
    }

    public function test_later_campaign_click_cannot_replace_first_touch_campaign_provenance(): void
    {
        $organization = $this->organization();
        $partner = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $firstLink = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Instagram — Stories',
            channel: ReferralCampaignChannel::Instagram,
        );
        $laterLink = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Telegram — чат',
            channel: ReferralCampaignChannel::Telegram,
        );
        $referred = Client::factory()->forOrganization($organization)->create(['lead_source' => null]);
        ClientAttribution::forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $referred->getKey(),
            'source_type' => 'referral',
            'referral_code' => $firstLink->public_token,
            'capture_channel' => 'portal',
            'captured_at' => now(),
            'accepted_at' => now(),
        ]);
        $sessionId = 'later-campaign-session';
        app(CapturePreAuthAttribution::class)->handle(
            sessionId: $sessionId,
            input: ['referral_code' => $laterLink->public_token],
            captureChannel: 'portal',
            captureContext: 'referral_route',
        );
        app(RegisterClientAcquisition::class)->handle($organization, $referred, $sessionId);

        app(FinalizeClientAcquisition::class)->handle($referred, $sessionId);

        $relationship = ReferralRelationship::query()->where('referred_client_id', $referred->getKey())->sole();
        self::assertSame($firstLink->getKey(), $relationship->referral_campaign_link_id);
        self::assertSame($firstLink->public_token, ClientAttribution::query()->where('client_id', $referred->getKey())->value('referral_code'));
    }

    private function organization(): Organization
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return $organization;
    }
}
