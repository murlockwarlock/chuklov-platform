<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\CreateReferralCampaignLink;
use App\Modules\Referrals\Application\DeactivateReferralCampaignLink;
use App\Modules\Referrals\Application\DeactivateReferralPartner;
use App\Modules\Referrals\Application\RecordReferralLinkVisit;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
use App\Modules\Referrals\Domain\Models\ReferralLinkVisit;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ReferralPartnerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_activation_is_idempotent_and_creates_one_default_link(): void
    {
        $organization = $this->organization();
        $client = Client::factory()->forOrganization($organization)->create();

        $first = app(ActivateReferralPartner::class)->handle($client, 'portal');
        $second = app(ActivateReferralPartner::class)->handle($client, 'portal');

        self::assertSame($first->getKey(), $second->getKey());
        self::assertSame(1, ReferralPartnerProfile::query()->where('client_id', $client->getKey())->count());
        self::assertSame(1, ReferralCampaignLink::query()->where('partner_client_id', $client->getKey())->count());
        self::assertTrue(ReferralCampaignLink::query()->where('partner_client_id', $client->getKey())->where('is_default', true)->exists());
    }

    public function test_active_partner_can_create_multiple_named_channel_links(): void
    {
        $organization = $this->organization();
        $client = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($client, 'portal');

        $instagram = app(CreateReferralCampaignLink::class)->handle(
            client: $client,
            name: 'Instagram — шапка профиля',
            channel: ReferralCampaignChannel::Instagram,
        );
        $telegram = app(CreateReferralCampaignLink::class)->handle(
            client: $client,
            name: 'Telegram — мой канал',
            channel: ReferralCampaignChannel::Telegram,
        );

        self::assertNotSame($instagram->public_token, $telegram->public_token);
        self::assertSame(ReferralCampaignChannel::Instagram, $instagram->channel);
        self::assertSame(ReferralCampaignChannel::Telegram, $telegram->channel);
    }

    public function test_inactive_partner_cannot_create_links_and_disabled_link_cannot_record_visits(): void
    {
        $organization = $this->organization();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($client, 'portal');
        $link = app(CreateReferralCampaignLink::class)->handle(
            client: $client,
            name: 'Сайт — лендинг',
            channel: ReferralCampaignChannel::Website,
        );

        app(DeactivateReferralCampaignLink::class)->handle($link, $client);
        self::assertNull(app(RecordReferralLinkVisit::class)->handle($link->public_token, 'disabled-session'));
        self::assertSame(0, ReferralLinkVisit::query()->count());

        app(DeactivateReferralPartner::class)->handle($client, $admin);

        $this->expectException(ValidationException::class);
        app(CreateReferralCampaignLink::class)->handle(
            client: $client,
            name: 'После отключения',
            channel: ReferralCampaignChannel::Other,
        );
    }

    public function test_partner_actions_reject_clients_from_another_organization(): void
    {
        $organization = $this->organization();
        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();

        $this->expectException(HttpException::class);
        app(ActivateReferralPartner::class)->handle($foreignClient, 'portal');
    }

    private function organization(): Organization
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return $organization;
    }
}
