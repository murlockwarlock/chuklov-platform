<?php

namespace Tests\Feature;

use App\Modules\Attribution\Application\CapturePreAuthAttribution;
use App\Modules\Identity\Application\ConsumeTelegramWebAuthentication;
use App\Modules\Identity\Application\InvalidTelegramWebAuthentication;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientAcquisitionRegistration;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientTelegramAuthenticationRequest;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Referrals\Application\EnsureReferralIdentity;
use App\Modules\Referrals\Application\FinalizeClientAcquisition;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class MilestoneTwoTelegramWebAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_bot_identity_creates_a_client_and_authenticates_the_requesting_browser(): void
    {
        $this->organizationWithClientRecords();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');

        $this->withSession(['portal.locale' => 'en']);
        $this->post(route('portal.telegram.web.request'))->assertRedirect(route('portal.home'));

        $url = session('telegram_web_auth.url');
        self::assertIsString($url);
        $token = $this->tokenFromUrl($url);
        self::assertStringNotContainsString($token, ClientTelegramAuthenticationRequest::query()->sole()->toJson());

        $bot = $this->fakeBot(720001, 'Новый', 'Клиент', username: 'new_client');
        $bot->hearText('/start web_'.$token)->reply();
        $bot->assertCalled('sendMessage', 1)
            ->assertReplyText('Вход подтверждён. Вернитесь в браузер.');

        $client = Client::query()->sole();
        $identity = ClientChannelIdentity::query()->sole();
        self::assertSame($client->id, $identity->client_id);
        self::assertSame('720001', $identity->external_id);
        self::assertSame('new_client', $identity->external_username);
        self::assertSame(ChannelIdentityStatus::Verified, $identity->verification_status);

        $this->getJson(route('portal.telegram.web.status'))
            ->assertOk()
            ->assertJson([
                'status' => 'authenticated',
                'redirect' => route('portal.home'),
            ])
            ->assertSessionHas('client_portal.client_id', $client->id);

        self::assertSame('en', $client->refresh()->language);
        self::assertNotNull(ClientTelegramAuthenticationRequest::query()->sole()->consumed_at);
    }

    public function test_existing_telegram_identity_authenticates_the_same_client_without_profile_matching(): void
    {
        $organization = $this->organizationWithClientRecords();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Сохранённое имя']);
        ClientChannelIdentity::factory()->forClient($client)->create([
            'channel' => 'telegram',
            'external_id' => '720002',
            'external_username' => 'old_client',
            'verification_status' => ChannelIdentityStatus::Verified,
        ]);

        $this->post(route('portal.telegram.web.request'))->assertRedirect();
        $bot = $this->fakeBot(720002, 'Другое', 'Имя', username: 'updated_client');
        $bot->hearText('/start web_'.$this->tokenFromUrl((string) session('telegram_web_auth.url')))->reply();

        $this->getJson(route('portal.telegram.web.status'))
            ->assertOk()
            ->assertSessionHas('client_portal.client_id', $client->id);

        self::assertSame(1, Client::query()->count());
        self::assertSame('Сохранённое имя', $client->refresh()->full_name);
        self::assertSame('updated_client', ClientChannelIdentity::query()->sole()->external_username);
    }

    public function test_consumed_web_auth_can_retry_acquisition_finalization_after_a_process_boundary(): void
    {
        $organization = $this->organizationWithClientRecords();
        app(OrganizationContext::class)->set($organization);
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        $referrer = Client::factory()->forOrganization($organization)->create();
        $identity = app(EnsureReferralIdentity::class)->handle($referrer);
        $sessionId = 'm11a-telegram-web-acquisition-retry';
        app(CapturePreAuthAttribution::class)->handle(
            sessionId: $sessionId,
            input: ['referral_code' => $identity->public_code],
            captureChannel: 'portal',
            captureContext: 'referral_link',
        );

        $this->post(route('portal.telegram.web.request'))->assertRedirect(route('portal.home'));
        $requestId = session('telegram_web_auth.request_id');
        $browserBinding = session('telegram_web_auth.browser_binding');
        self::assertIsInt($requestId);
        self::assertIsString($browserBinding);

        $bot = $this->fakeBot(720006, 'Повтор', 'Вход');
        $bot->hearText('/start web_'.$this->tokenFromUrl((string) session('telegram_web_auth.url')))->reply();
        $client = Client::query()->where('id', '<>', $referrer->getKey())->sole();

        $consumedClient = app(ConsumeTelegramWebAuthentication::class)->handle($requestId, $browserBinding);
        self::assertInstanceOf(Client::class, $consumedClient);
        self::assertSame($client->getKey(), $consumedClient->getKey());
        self::assertNotNull(ClientTelegramAuthenticationRequest::query()->findOrFail($requestId)->consumed_at);
        $registration = ClientAcquisitionRegistration::query()
            ->where('client_id', $client->getKey())
            ->where('telegram_authentication_request_id', $requestId)
            ->sole();
        self::assertNull($registration->finalized_at);
        $retryClient = app(ConsumeTelegramWebAuthentication::class)->handle($requestId, $browserBinding);
        self::assertInstanceOf(Client::class, $retryClient);
        app(FinalizeClientAcquisition::class)->handle($retryClient, $sessionId, $requestId);
        app(FinalizeClientAcquisition::class)->handle($retryClient, $sessionId, $requestId);

        self::assertSame($referrer->getKey(), ReferralRelationship::query()
            ->where('referred_client_id', $client->getKey())
            ->value('referrer_client_id'));
    }

    public function test_token_is_single_use_and_only_the_bound_browser_session_can_consume_it(): void
    {
        $this->organizationWithClientRecords();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');

        $this->post(route('portal.telegram.web.request'))->assertRedirect();
        $authenticationRequest = ClientTelegramAuthenticationRequest::query()->sole();
        $token = $this->tokenFromUrl((string) session('telegram_web_auth.url'));
        $bot = $this->fakeBot(720003, 'Один', 'Раз');
        $bot->hearText('/start web_'.$token)->reply();
        $bot->hearText('/start web_'.$token)->reply();

        self::assertSame(1, Client::query()->count());
        $this->expectException(InvalidTelegramWebAuthentication::class);
        app(ConsumeTelegramWebAuthentication::class)->handle($authenticationRequest->id, 'another-browser-session');
    }

    public function test_expired_token_does_not_authenticate_or_create_a_client(): void
    {
        $this->organizationWithClientRecords();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        config()->set('portal.telegram.web_auth_ttl', 1);

        $this->post(route('portal.telegram.web.request'))->assertRedirect();
        $token = $this->tokenFromUrl((string) session('telegram_web_auth.url'));
        Carbon::setTestNow(now()->addSeconds(2));
        $bot = $this->fakeBot(720004, 'Поздний', 'Клиент');
        $bot->hearText('/start web_'.$token)->reply();

        self::assertSame(0, Client::query()->count());
        $this->getJson(route('portal.telegram.web.status'))
            ->assertStatus(410)
            ->assertJson(['status' => 'expired']);
        Carbon::setTestNow();
    }

    public function test_revoked_verified_identity_cannot_finish_browser_authentication(): void
    {
        $this->organizationWithClientRecords();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');

        $this->post(route('portal.telegram.web.request'))->assertRedirect();
        $bot = $this->fakeBot(720005, 'Отозванный', 'Клиент');
        $bot->hearText('/start web_'.$this->tokenFromUrl((string) session('telegram_web_auth.url')))->reply();
        ClientChannelIdentity::query()->sole()->forceFill([
            'verification_status' => ChannelIdentityStatus::Revoked,
        ])->save();

        $this->getJson(route('portal.telegram.web.status'))
            ->assertStatus(410)
            ->assertJson(['status' => 'expired'])
            ->assertSessionMissing('client_portal.client_id');
    }

    private function tokenFromUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $payload = $query['start'] ?? null;
        self::assertIsString($payload);
        self::assertStringStartsWith('web_', $payload);

        return substr($payload, 4);
    }

    private function fakeBot(int $id, string $firstName, string $lastName, ?string $username = null): Nutgram
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $bot->setCommonUser(User::make(
            id: $id,
            is_bot: false,
            first_name: $firstName,
            last_name: $lastName,
            username: $username,
            language_code: 'ru',
        ));

        return $bot;
    }

    private function organizationWithClientRecords(): Organization
    {
        $organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->id);

        return $organization;
    }
}
