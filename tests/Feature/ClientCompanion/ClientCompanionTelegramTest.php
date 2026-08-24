<?php

namespace Tests\Feature\ClientCompanion;

use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

final class ClientCompanionTelegramTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->client = Client::factory()->forOrganization($this->organization)->create();
        config()->set('tenancy.default_organization_id', $this->organization->getKey());
        Queue::fake();
    }

    public function test_verified_private_text_goes_directly_to_one_companion_conversation_without_a_button(): void
    {
        $this->verifyTelegram('810001');
        $bot = $this->fakeBot(810001, ChatType::PRIVATE, 910001);

        $bot->hearMessage(['message_id' => 101, 'text' => 'Привет'])->reply();
        Carbon::setTestNow(now()->addSeconds(2));
        $bot->hearMessage(['message_id' => 102, 'text' => 'После массажа шея тянет'])->reply();
        Carbon::setTestNow();

        self::assertSame(1, Conversation::query()->where('client_id', $this->client->getKey())->count());
        self::assertSame(2, CompanionTurn::query()->where('client_id', $this->client->getKey())->count());
        self::assertSame(2, ConversationMessage::query()->where('client_id', $this->client->getKey())->count());
        $bot->assertNoReply();
    }

    public function test_unverified_private_text_uses_the_safe_link_path_and_never_creates_ai_state(): void
    {
        $bot = $this->fakeBot(810002, ChatType::PRIVATE, 910002);

        $bot->hearMessage(['message_id' => 201, 'text' => 'Расскажите про боль'])->reply();

        self::assertSame(0, CompanionTurn::query()->count());
        $bot->assertCalled('sendMessage');
        $history = array_values($bot->getRequestHistory());
        $body = json_decode((string) array_values($history[0])[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('подключите Telegram', (string) ($body['text'] ?? ''));
    }

    public function test_commands_are_not_forwarded_and_group_or_channel_text_is_ignored(): void
    {
        $this->verifyTelegram('810003');
        $bot = $this->fakeBot(810003, ChatType::PRIVATE, 910003);

        $bot->hearText('/help')->reply();
        $bot->hearText('/start')->reply();
        self::assertSame(0, CompanionTurn::query()->count());

        $groupBot = $this->fakeBot(810003, ChatType::GROUP, -910003);
        $groupBot->hearMessage(['message_id' => 301, 'text' => 'Сообщение из группы'])->reply();
        $channelBot = $this->fakeBot(810003, ChatType::CHANNEL, -910004);
        $channelBot->hearMessage(['message_id' => 302, 'text' => 'Сообщение из канала'])->reply();

        self::assertSame(0, CompanionTurn::query()->count());
    }

    private function verifyTelegram(string $externalId): void
    {
        ClientChannelIdentity::factory()->forClient($this->client)->create([
            'channel' => 'telegram',
            'external_id' => $externalId,
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verified_at' => now(),
        ]);
    }

    private function fakeBot(int $userId, ChatType $chatType, int $chatId): Nutgram
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $bot->setCommonUser(User::make(
            id: $userId,
            is_bot: false,
            first_name: 'Client',
            language_code: 'ru',
        ));
        $bot->setCommonChat(Chat::fromArray([
            'id' => $chatId,
            'type' => $chatType->value,
        ]));

        return $bot;
    }
}
