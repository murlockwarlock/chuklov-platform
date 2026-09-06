<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\CreateReferralCampaignLink;
use App\Modules\Referrals\Application\DeactivateReferralCampaignLink;
use App\Modules\Referrals\Application\DeactivateReferralPartner;
use App\Modules\Referrals\Application\EnsureReferralIdentity;
use App\Modules\Referrals\Application\GetReferralPartnerOverview;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\Support\TelegramInitData;
use Tests\TestCase;

final class ReferralTelegramFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
    }

    public function test_personal_and_campaign_links_are_telegram_first_and_fit_start_parameter_limit(): void
    {
        $organization = $this->organization();
        $partner = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $campaign = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Instagram',
            channel: ReferralCampaignChannel::Instagram,
        );

        $overview = app(GetReferralPartnerOverview::class)->handle($partner);

        $this->assertReferralTelegramUrl($overview['link'], app(EnsureReferralIdentity::class)->handle($partner)->public_code);
        $campaignOverview = collect($overview['links'])->firstWhere('name', 'Instagram');
        self::assertIsArray($campaignOverview);
        $this->assertReferralTelegramUrl($campaignOverview['shareUrl'], $campaign->public_token);
    }

    public function test_personal_and_campaign_telegram_starts_open_the_allowlisted_mini_app(): void
    {
        $organization = $this->organization();
        $partner = Client::factory()->forOrganization($organization)->create();
        $profile = app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $personal = app(EnsureReferralIdentity::class)->handle($partner);
        $campaign = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Telegram',
            channel: ReferralCampaignChannel::Telegram,
        );

        self::assertTrue($profile->isActive());
        $personalBot = $this->fakeBot(930001);
        $personalBot->hearText('/start ref_'.$personal->public_code)->reply();
        $personalBody = $this->requestBody($personalBot, 0);
        self::assertSame(
            'https://mini.example.test/portal/telegram/launch/portal?referral_code='.$personal->public_code,
            $personalBody['reply_markup']['inline_keyboard'][0][0]['web_app']['url'],
        );

        $campaignBot = $this->fakeBot(930002);
        $campaignBot->hearText('/start ref_'.$campaign->public_token)->reply();
        $campaignBody = $this->requestBody($campaignBot, 0);
        $button = $campaignBody['reply_markup']['inline_keyboard'][0][0];
        self::assertSame('https://mini.example.test/portal/telegram/launch/portal?referral_code='.$campaign->public_token, $button['web_app']['url']);
        self::assertArrayNotHasKey('url', $button);
        self::assertSame(1, \DB::table('referral_link_visits')->where('campaign_link_id', $campaign->getKey())->count());
    }

    public function test_disabled_campaign_and_inactive_partner_cannot_start_new_referral_attribution(): void
    {
        $organization = $this->organization();
        $admin = User::factory()->forOrganization($organization)->create();
        $partner = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $personal = app(EnsureReferralIdentity::class)->handle($partner);
        $campaign = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Disabled campaign',
            channel: ReferralCampaignChannel::Website,
        );
        app(DeactivateReferralCampaignLink::class)->handle($campaign, $partner);

        $disabledBot = $this->fakeBot(930003);
        $disabledBot->hearText('/start ref_'.$campaign->public_token)->reply();
        $disabledBody = $this->requestBody($disabledBot, 0);
        self::assertArrayNotHasKey('reply_markup', $disabledBody);
        self::assertSame(0, \DB::table('referral_link_visits')->where('campaign_link_id', $campaign->getKey())->count());

        $activeCampaign = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Inactive partner campaign',
            channel: ReferralCampaignChannel::Telegram,
        );
        app(DeactivateReferralPartner::class)->handle($partner, $admin);

        $inactiveBot = $this->fakeBot(930004);
        $inactiveBot->hearText('/start ref_'.$activeCampaign->public_token)->reply();
        $inactiveBody = $this->requestBody($inactiveBot, 0);
        self::assertArrayNotHasKey('reply_markup', $inactiveBody);
        self::assertSame(0, \DB::table('referral_link_visits')->where('campaign_link_id', $activeCampaign->getKey())->count());

        $inactivePersonalBot = $this->fakeBot(930008);
        $inactivePersonalBot->hearText('/start ref_'.$personal->public_code)->reply();
        self::assertArrayNotHasKey('reply_markup', $this->requestBody($inactivePersonalBot, 0));
    }

    public function test_legacy_referral_route_enters_telegram_without_double_counting_campaign_visits(): void
    {
        $organization = $this->organization();
        $partner = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $campaign = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Legacy URL',
            channel: ReferralCampaignChannel::Other,
        );

        $this->get(route('portal.referral', ['referralCode' => $campaign->public_token]))
            ->assertRedirect('https://t.me/chuklov_test_bot?start=ref_'.$campaign->public_token);
        self::assertSame(0, \DB::table('referral_link_visits')->where('campaign_link_id', $campaign->getKey())->count());

        $bot = $this->fakeBot(930005);
        $bot->hearText('/start ref_'.$campaign->public_token)->reply();
        $bot->hearText('/start ref_'.$campaign->public_token)->reply();

        self::assertSame(1, \DB::table('referral_link_visits')->where('campaign_link_id', $campaign->getKey())->count());
    }

    public function test_mini_app_launch_captures_bounded_referral_context_without_start_parameter(): void
    {
        $organization = $this->organization();
        $partner = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $campaign = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Query campaign',
            channel: ReferralCampaignChannel::Telegram,
        );

        $this->get(route('portal.telegram.launch', ['entry' => 'portal']).'?referral_code='.$campaign->public_token)
            ->assertRedirect(route('portal.home', ['telegram_entry' => 'portal'], false));

        self::assertDatabaseHas('pre_auth_attributions', [
            'referral_code' => $campaign->public_token,
            'capture_context' => 'portal_entry',
        ]);
    }

    public function test_campaign_provenance_survives_mini_app_authentication(): void
    {
        $organization = $this->organization();
        $partner = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $campaign = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Auth campaign',
            channel: ReferralCampaignChannel::Telegram,
        );
        $bot = $this->fakeBot(930006);
        $bot->hearText('/start ref_'.$campaign->public_token)->reply();
        $webAppUrl = $this->requestBody($bot, 0)['reply_markup']['inline_keyboard'][0][0]['web_app']['url'];
        $parts = parse_url($webAppUrl);

        $this->get($parts['path'].'?'.$parts['query'])->assertRedirect();
        $this->post(route('portal.telegram.auth'), [
            'initData' => TelegramInitData::make(
                userId: 930006,
                authDate: now()->timestamp,
                startParameter: 'ref_'.$campaign->public_token,
            ),
            'launchEntry' => 'portal',
        ])->assertRedirect(route('portal.home'))
            ->assertSessionMissing('telegram_auth_error');

        self::assertDatabaseCount('client_acquisition_registrations', 1);
        self::assertDatabaseHas('client_attributions', ['referral_code' => $campaign->public_token]);
        self::assertSame($campaign->getKey(), ReferralRelationship::query()->sole()->referral_campaign_link_id);
        self::assertSame($partner->getKey(), ReferralRelationship::query()->sole()->referrer_client_id);
    }

    public function test_telegram_mini_app_first_touch_cannot_be_reassigned_by_a_later_referral(): void
    {
        $organization = $this->organization();
        $partner = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $first = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'First campaign',
            channel: ReferralCampaignChannel::Instagram,
        );
        $second = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Later campaign',
            channel: ReferralCampaignChannel::Telegram,
        );
        $bot = $this->fakeBot(930007);

        $bot->hearText('/start ref_'.$first->public_token)->reply();
        $firstUrl = $this->requestBody($bot, 0)['reply_markup']['inline_keyboard'][0][0]['web_app']['url'];
        $firstParts = parse_url($firstUrl);
        $this->get($firstParts['path'].'?'.$firstParts['query'])->assertRedirect();
        $this->post(route('portal.telegram.auth'), [
            'initData' => TelegramInitData::make(
                userId: 930007,
                authDate: now()->timestamp,
                startParameter: 'ref_'.$first->public_token,
            ),
            'launchEntry' => 'portal',
        ])->assertRedirect(route('portal.home'));

        $bot->hearText('/start ref_'.$second->public_token)->reply();
        $secondUrl = $this->requestBody($bot, 0)['reply_markup']['inline_keyboard'][0][0]['web_app']['url'];
        $secondParts = parse_url($secondUrl);
        $this->get($secondParts['path'].'?'.$secondParts['query'])->assertRedirect();

        $this->post(route('portal.telegram.auth'), [
            'initData' => TelegramInitData::make(
                userId: 930007,
                authDate: now()->timestamp,
                startParameter: 'ref_'.$second->public_token,
            ),
            'launchEntry' => 'portal',
        ])->assertRedirect(route('portal.home'));

        self::assertSame($first->getKey(), ReferralRelationship::query()->sole()->referral_campaign_link_id);
    }

    private function assertReferralTelegramUrl(string $url, string $token): void
    {
        $parts = parse_url($url);
        self::assertSame('https', $parts['scheme'] ?? null);
        self::assertSame('t.me', $parts['host'] ?? null);
        parse_str((string) ($parts['query'] ?? ''), $query);
        self::assertSame('ref_'.$token, $query['start'] ?? null);
        self::assertLessThanOrEqual(64, strlen((string) ($query['start'] ?? '')));
    }

    private function requestBody(Nutgram $bot, int $index): array
    {
        $history = array_values($bot->getRequestHistory());
        $request = array_values($history[$index])[0];
        $body = FakeNutgram::getActualData($request, ['show_caption_above_media' => true]);
        self::assertIsArray($body);

        return $body;
    }

    private function fakeBot(int $id): Nutgram
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $bot->setCommonUser(TelegramUser::make(
            id: $id,
            is_bot: false,
            first_name: 'Referral',
            last_name: 'Tester',
            language_code: 'ru',
        ));

        return $bot;
    }

    private function organization(): Organization
    {
        $organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return $organization;
    }
}
