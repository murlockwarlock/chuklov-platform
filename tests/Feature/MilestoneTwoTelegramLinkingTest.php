<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientChannelLinkToken;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Security\Domain\Models\AuditEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class MilestoneTwoTelegramLinkingTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_client_can_create_a_single_use_link_and_bot_evidence_connects_identity(): void
    {
        $organization = $this->organizationWithClientRecords();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        $client = Client::factory()->forOrganization($organization)->create();
        $this->withSession(['client_portal.client_id' => $client->id]);

        $this->post(route('portal.telegram.link'), [
            'client_id' => 999999,
            'organization_id' => 999999,
            'telegram_user_id' => 999999,
        ])->assertRedirect(route('portal.services.index'));

        $url = session('telegram_link_url');
        self::assertIsString($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['start'] ?? null;
        self::assertIsString($token);
        self::assertSame(1, ClientChannelLinkToken::query()->count());

        $bot = $this->fakeBot(710001, 'Unmatched', 'Telegram User');
        $bot->hearText('/start '.$token)->reply();

        $identity = ClientChannelIdentity::query()->sole();
        $linkToken = ClientChannelLinkToken::query()->sole();
        self::assertSame($client->id, $identity->client_id);
        self::assertSame('710001', $identity->external_id);
        self::assertSame(ChannelIdentityStatus::Verified, $identity->verification_status);
        self::assertNotNull($linkToken->consumed_at);
        self::assertStringNotContainsString($token, AuditEvent::query()->get()->toJson());
    }

    public function test_expired_and_replayed_link_tokens_fail_without_creating_identity(): void
    {
        $organization = $this->organizationWithClientRecords();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        config()->set('portal.telegram.link_ttl', 1);
        $client = Client::factory()->forOrganization($organization)->create();
        $this->withSession(['client_portal.client_id' => $client->id]);

        $this->post(route('portal.telegram.link'))->assertRedirect();
        $url = session('telegram_link_url');
        self::assertIsString($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['start'];
        Carbon::setTestNow(now()->addSeconds(2));

        $bot = $this->fakeBot(710002, 'Expired', 'Telegram User');
        $bot->hearText('/start '.$token)->reply();
        Carbon::setTestNow();

        self::assertSame(0, ClientChannelIdentity::query()->count());

        config()->set('portal.telegram.link_ttl', 600);
        $this->post(route('portal.telegram.link'))->assertRedirect();
        $url = session('telegram_link_url');
        self::assertIsString($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['start'];
        $bot = $this->fakeBot(710003, 'Replay', 'Telegram User');
        $bot->hearText('/start '.$token)->reply();
        $bot->hearText('/start '.$token)->reply();

        self::assertSame(1, ClientChannelIdentity::query()->count());
    }

    public function test_expired_link_flow_is_consumed_before_a_replacement_is_created(): void
    {
        $organization = $this->organizationWithClientRecords();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        $client = Client::factory()->forOrganization($organization)->create();
        $expired = ClientChannelLinkToken::factory()->forClient($client)->create([
            'expires_at' => now()->subSecond(),
        ]);
        $this->withSession(['client_portal.client_id' => $client->id]);

        $this->post(route('portal.telegram.link'))->assertRedirect();

        $expired->refresh();
        $replacement = ClientChannelLinkToken::query()
            ->where('client_id', $client->id)
            ->whereNull('consumed_at')
            ->sole();

        self::assertNotNull($expired->consumed_at);
        self::assertTrue($replacement->expires_at->isFuture());
    }

    public function test_link_is_organization_scoped_and_frontend_identity_fields_do_not_link_anything(): void
    {
        $organization = $this->organizationWithClientRecords();
        $otherOrganization = Organization::factory()->create();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        $client = Client::factory()->forOrganization($organization)->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        ClientChannelIdentity::factory()->forClient($otherClient)->create([
            'channel' => 'telegram',
            'external_id' => '710004',
        ]);
        $this->withSession(['client_portal.client_id' => $client->id]);

        $this->post(route('portal.telegram.link'), [
            'telegram_user_id' => '710004',
            'telegram_display_name' => 'Other Client',
        ])->assertRedirect();
        self::assertSame(0, ClientChannelIdentity::query()->where('client_id', $client->id)->count());

        $url = session('telegram_link_url');
        self::assertIsString($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['start'];
        $bot = $this->fakeBot(710004, 'Different', 'Verified Evidence');
        $bot->hearText('/start '.$token)->reply();

        self::assertSame(1, ClientChannelIdentity::query()->where('client_id', $client->id)->count());
        self::assertSame(1, ClientChannelIdentity::query()->where('client_id', $otherClient->id)->count());
    }

    public function test_bot_identity_evidence_rejects_a_bot_user(): void
    {
        $this->organizationWithClientRecords();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');

        $bot = $this->fakeBot(710005, 'Not', 'A User', true);
        $bot->hearText('/start invalid-token')->reply();

        self::assertSame(0, ClientChannelIdentity::query()->count());
    }

    private function fakeBot(int $id, string $firstName, string $lastName, bool $isBot = false): Nutgram
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $bot->setCommonUser(User::make(
            id: $id,
            is_bot: $isBot,
            first_name: $firstName,
            last_name: $lastName,
            language_code: 'en',
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
