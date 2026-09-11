<?php

namespace Tests\Feature\ClientCompanion;

use App\Filament\Pages\Messages;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\User;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Actions\ReplyToCompanion;
use App\Modules\ClientCompanion\Application\Services\ReadCompanionWorkspace;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Infrastructure\Jobs\DeliverCompanionMessage;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class MessagesWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($this->organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $this->admin = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();
        $this->client = Client::factory()->forOrganization($this->organization)->create([
            'full_name' => 'Мария Сообщения',
            'email' => 'messages@example.com',
            'phone' => '87052865589',
            'language' => 'ru',
        ]);
        $this->resolveContext();
        Queue::fake();
    }

    public function test_workspace_searches_and_renders_one_client_ai_staff_history_without_creating_empty_conversations(): void
    {
        $turn = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'portal',
            body: 'Вопрос клиента',
            idempotencyKey: 'messages-workspace-0001',
            originExternalId: 'portal:messages-workspace-0001',
            locale: 'ru',
        );
        $conversation = Conversation::query()->findOrFail($turn->conversation_id);
        app(RecordCompanionMessage::class)->handle(
            organizationId: $this->organization->getKey(),
            client: $this->client,
            conversation: $conversation,
            channel: 'portal',
            direction: ConversationDirection::Outbound,
            authorType: ConversationAuthorType::Ai,
            body: 'Ответ AI',
            contextEpoch: $conversation->context_epoch,
            metadata: ['message_type' => 'ai_reply', 'locale' => 'ru', 'transport' => 'portal'],
        );
        app(ReplyToCompanion::class)->handle($this->admin, $this->client, 'Ответ специалиста');

        $emptyClient = Client::factory()->forOrganization($this->organization)->create([
            'full_name' => 'Новый клиент без диалога',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($this->admin)
            ->test(Messages::class)
            ->set('search', 'Мария Сообщения')
            ->assertSee('Мария Сообщения')
            ->call('selectClient', $this->client->getKey())
            ->assertActionExists('export')
            ->assertActionExists('technicalMetadata')
            ->assertSee('Вопрос клиента')
            ->assertSee('Ответ AI')
            ->assertSee('Ответ специалиста')
            ->assertSee('Клиент')
            ->assertSee('AI-помощник')
            ->assertSee('Специалист')
            ->assertSee('Напишите сообщение...')
            ->assertSee('Язык: Русский')
            ->assertSee('+7 705 286-55-89')
            ->assertDontSee('87052865589')
            ->assertSee('title="messages@example.com"', false)
            ->assertDontSee('Язык: ru')
            ->assertDontSee('PDF, TXT, JPG, PNG или WebP; до 20 МБ.')
            ->assertDontSee('Выбрать ранее загруженный файл')
            ->assertDontSee('Открыть технические данные AI')
            ->assertSee('messages-send-action')
            ->assertSee('messages-attachment-trigger')
            ->assertSee('messages-attachment-summary')
            ->assertSee('messages-attachment-upload-hidden')
            ->assertDontSee('Открыть карточку клиента');

        $editor = $component->instance()->getSchemaComponent('form.body');
        self::assertInstanceOf(Textarea::class, $editor);
        self::assertSame('Напишите сообщение...', $editor->getPlaceholder());

        $upload = $component->instance()->getSchemaComponent('form.new_attachment');
        self::assertInstanceOf(FileUpload::class, $upload);
        self::assertStringContainsString('messages-attachment-upload-hidden', (string) ($upload->getExtraAttributes()['class'] ?? ''));
        self::assertArrayHasKey('x-on:messages-open-attachment.window', $upload->getExtraAlpineAttributes());

        $component
            ->set('search', 'Новый клиент без диалога')
            ->call('selectClient', $emptyClient->getKey())
            ->assertSee('История пока пуста')
            ->assertSee('Начните диалог');

        self::assertSame(0, Conversation::query()->where('client_id', $emptyClient->getKey())->count());

        self::assertSame(
            [$this->client->getKey()],
            array_column(app(ReadCompanionWorkspace::class)->dialogs($this->admin, 'messages@example.com'), 'clientId'),
        );
        self::assertSame(
            [$this->client->getKey()],
            array_column(app(ReadCompanionWorkspace::class)->dialogs($this->admin, '87052865589'), 'clientId'),
        );
    }

    public function test_workspace_does_not_search_or_open_a_foreign_client(): void
    {
        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create([
            'full_name' => 'Чужая организация',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($this->admin)
            ->test(Messages::class)
            ->set('search', 'Чужая организация')
            ->assertDontSee('Чужая организация');

        $this->expectException(ModelNotFoundException::class);
        $component->call('selectClient', $foreignClient->getKey())->assertStatus(404);
    }

    public function test_crm_reply_uses_verified_telegram_binding_for_the_existing_companion_conversation(): void
    {
        ClientChannelIdentity::factory()->forClient($this->client)->create([
            'channel' => 'telegram',
            'external_id' => '810009',
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verified_at' => now(),
        ]);
        $inbound = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Сообщение клиента',
            idempotencyKey: null,
            originExternalId: 'messages-workspace-telegram-0001',
            transportChatId: '810009',
            locale: 'ru',
        );

        Livewire::actingAs($this->admin)
            ->test(Messages::class)
            ->call('selectClient', $this->client->getKey())
            ->set('data.body', 'Сообщение из CRM')
            ->call('sendMessage')
            ->assertNotified('Сообщение принято к отправке в Telegram');

        self::assertSame(1, Conversation::query()->where('client_id', $this->client->getKey())->count());

        $conversation = Conversation::query()->where('client_id', $this->client->getKey())->sole();
        self::assertSame($conversation->getKey(), $inbound->conversation_id);
        self::assertSame(2, ConversationMessage::query()->where('conversation_id', $conversation->getKey())->count());
        $message = ConversationMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('author_type', ConversationAuthorType::Staff)
            ->sole();
        $delivery = CompanionDelivery::query()->where('conversation_message_id', $message->getKey())->sole();

        self::assertSame('telegram', $message->channel);
        self::assertSame('810009', $delivery->recipient_external_id);
        Queue::assertPushed(DeliverCompanionMessage::class);
    }

    public function test_crm_reply_without_telegram_keeps_the_real_channel_state_visible(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->admin)
            ->test(Messages::class)
            ->call('selectClient', $this->client->getKey())
            ->set('data.body', 'Сообщение без Telegram')
            ->call('sendMessage')
            ->assertNotified('Сообщение сохранено в истории')
            ->assertSee('Telegram не подключён');

        self::assertSame(0, CompanionDelivery::query()->count());
        self::assertSame('portal', ConversationMessage::query()->sole()->channel);
    }

    public function test_booking_client_name_links_to_the_scoped_client_resource(): void
    {
        $specialist = Specialist::factory()->forOrganization($this->organization)->create();
        $service = Service::factory()->forOrganization($this->organization)->create();
        $booking = Booking::factory()
            ->forClient($this->client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($this->admin)
            ->test(ViewBooking::class, ['record' => $booking->getKey()])
            ->assertSuccessful()
            ->assertSee($this->client->full_name)
            ->assertSee(ClientResource::getUrl('view', ['record' => $this->client]));
    }

    public function test_client_card_links_to_messages_with_the_client_selected(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->admin)
            ->test(ViewClient::class, ['record' => $this->client->getKey()])
            ->assertSuccessful()
            ->assertSee('Сообщения')
            ->assertSee(Messages::getUrl(['client' => $this->client->getKey()]));
    }

    public function test_switching_clients_resets_the_composer_target(): void
    {
        $otherClient = Client::factory()->forOrganization($this->organization)->create([
            'full_name' => 'Второй клиент',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($this->admin)
            ->test(Messages::class)
            ->call('selectClient', $this->client->getKey())
            ->set('data.body', 'Сообщение первому')
            ->call('selectClient', $otherClient->getKey())
            ->set('data.body', 'Сообщение второму')
            ->call('sendMessage');

        self::assertSame(0, ConversationMessage::query()->where('client_id', $this->client->getKey())->count());
        self::assertSame(1, ConversationMessage::query()->where('client_id', $otherClient->getKey())->where('author_type', ConversationAuthorType::Staff)->count());
    }

    public function test_booking_from_another_organization_is_not_resolvable_in_the_current_panel(): void
    {
        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();
        $foreignSpecialist = Specialist::factory()->forOrganization($foreignOrganization)->create();
        $foreignService = Service::factory()->forOrganization($foreignOrganization)->create();
        $foreignBooking = Booking::factory()
            ->forClient($foreignClient)
            ->forSpecialist($foreignSpecialist)
            ->forService($foreignService)
            ->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($this->admin)
            ->test(ViewBooking::class, ['record' => $foreignBooking->getKey()]);
    }

    private function resolveContext(): void
    {
        config()->set('tenancy.default_organization_id', $this->organization->getKey());
        app(OrganizationContext::class)->set($this->organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
}
