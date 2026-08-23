<?php

namespace Tests\Feature\ClientCompanion;

use App\Modules\AI\Application\Attachments\AiAttachmentResolver;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\Attachments\Domain\Contracts\AttachmentScannerInterface;
use App\Modules\Attachments\Infrastructure\Scanning\LocalDeterministicAttachmentScanner;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Actions\HandleTelegramCompanionPhoto;
use App\Modules\ClientCompanion\Application\Actions\UploadCompanionImages;
use App\Modules\ClientCompanion\Application\Services\AssembleCompanionContext;
use App\Modules\ClientCompanion\Application\Services\CompanionMessageBodyReader;
use App\Modules\ClientCompanion\Domain\Enums\CompanionImageReferenceMode;
use App\Modules\ClientCompanion\Domain\Models\CompanionMessageAttachment;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Files\Image;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

final class ClientCompanionImageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->app->instance(AttachmentScannerInterface::class, new LocalDeterministicAttachmentScanner);
        $this->organization = Organization::factory()->create();
        $this->client = Client::factory()->forOrganization($this->organization)->create();
        app(OrganizationContext::class)->set($this->organization);
        config()->set('tenancy.default_organization_id', $this->organization->getKey());
        Queue::fake();
    }

    public function test_uploaded_image_is_private_validated_and_can_be_resolved_for_client_companion(): void
    {
        $attachment = app(UploadCompanionImages::class)->handle($this->client, [$this->image('pain.jpg')])[0];

        self::assertSame('companion_image', $attachment->attachment_type->value);
        self::assertNull($attachment->uploaded_by_user_id);
        self::assertSame('private', $attachment->disk);
        self::assertStringStartsWith('medical/attachments/'.$this->organization->getKey().'/', $attachment->storage_path);
        Storage::disk('private')->assertExists($attachment->storage_path);

        $resolved = app(AiAttachmentResolver::class)->resolve(
            organizationId: $this->organization->getKey(),
            capability: AiCapability::ClientCompanion,
            references: [new AiInputReference('companion_attachment', $attachment->getKey())],
            actor: null,
            clientId: $this->client->getKey(),
        );

        self::assertCount(1, $resolved['files']);
        self::assertInstanceOf(Image::class, $resolved['files'][0]);
        self::assertSame('companion_attachment', $resolved['provenance'][0]['reference_type']);
    }

    public function test_verified_telegram_photo_with_caption_is_one_image_companion_turn(): void
    {
        ClientChannelIdentity::factory()->forClient($this->client)->create([
            'channel' => 'telegram',
            'external_id' => '810100',
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verified_at' => now(),
        ]);
        $fixture = UploadedFile::fake()->image('telegram.jpg', 100, 100);
        $bot = FakeNutgram::instance(null, [
            new Response(200, [], json_encode([
                'ok' => true,
                'result' => [
                    'file_id' => 'file-id',
                    'file_unique_id' => 'file-unique-id',
                    'file_path' => 'photos/telegram.jpg',
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], (string) file_get_contents($fixture->getRealPath())),
        ]);
        $bot->setCommonUser(User::make(id: 810100, is_bot: false, first_name: 'Client', language_code: 'ru'));
        $bot->setCommonChat(Chat::fromArray(['id' => 910100, 'type' => ChatType::PRIVATE->value]));
        $handler = app(HandleTelegramCompanionPhoto::class);
        $bot->onPhoto(function (Nutgram $bot) use ($handler): void {
            $handler->handle($bot);
        });
        $bot->hearMessage([
            'message_id' => 701,
            'photo' => [['file_id' => 'file-id', 'file_unique_id' => 'file-unique-id', 'width' => 100, 'height' => 100]],
            'caption' => 'Вот тут болит',
        ]);

        $bot->reply();

        $turn = CompanionTurn::query()->sole();
        self::assertSame('image', $turn->input_modality);
        self::assertSame('Вот тут болит', $turn->inboundMessage()->firstOrFail()->body === null
            ? app(CompanionMessageBodyReader::class)->read($this->organization->getKey(), $turn->inboundMessage()->firstOrFail())
            : $turn->inboundMessage()->firstOrFail()->body);
        self::assertSame(1, CompanionMessageAttachment::query()->count());
    }

    public function test_album_items_are_one_durable_turn_and_preserve_caption_and_order(): void
    {
        $first = app(UploadCompanionImages::class)->handle($this->client, [$this->image('first.jpg')])[0];
        $second = app(UploadCompanionImages::class)->handle($this->client, [$this->image('second.jpg')])[0];
        $accept = app(AcceptCompanionMessage::class);

        $turn = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Вторая подпись',
            idempotencyKey: null,
            originExternalId: 'album-chat:22',
            transportChatId: 'album-chat',
            attachmentIds: [$second->getKey()],
            mediaGroupId: 'album-100',
            sourceOrdinal: 22,
        );
        $sameTurn = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Первая подпись',
            idempotencyKey: null,
            originExternalId: 'album-chat:21',
            transportChatId: 'album-chat',
            attachmentIds: [$first->getKey()],
            mediaGroupId: 'album-100',
            sourceOrdinal: 21,
        );
        $duplicate = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Первая подпись',
            idempotencyKey: null,
            originExternalId: 'album-chat:21',
            transportChatId: 'album-chat',
            attachmentIds: [$first->getKey()],
            mediaGroupId: 'album-100',
            sourceOrdinal: 21,
        );
        self::assertSame($turn->getKey(), $sameTurn->getKey());
        self::assertSame($turn->getKey(), $duplicate->getKey());
        self::assertSame(1, CompanionTurn::query()->count());
        self::assertSame(2, ConversationMessage::query()->count());
        self::assertSame(2, CompanionMessageAttachment::query()->count());
        self::assertSame(2, $turn->fresh()->input_item_count);

        $context = app(AssembleCompanionContext::class)->handle(
            $this->organization->getKey(),
            $turn->conversation()->firstOrFail(),
            $turn->fresh(),
        );
        self::assertLessThan(mb_strpos($context['current_message'], 'Вторая подпись'), mb_strpos($context['current_message'], 'Первая подпись'));
        self::assertStringContainsString('[Изображение: 1]', $context['current_message']);
    }

    public function test_ten_photo_album_stays_one_turn_when_image_limits_are_not_exceeded(): void
    {
        $startedAt = Carbon::create(2026, 8, 24, 18, 0, 0, 'UTC');
        Carbon::setTestNow($startedAt);
        try {
            $accept = app(AcceptCompanionMessage::class);
            $turn = null;

            for ($index = 1; $index <= 10; $index++) {
                Carbon::setTestNow($startedAt->copy()->addSeconds(($index - 1) * 2));
                $attachment = app(UploadCompanionImages::class)->handle($this->client, [$this->image('album-'.$index.'.jpg')])[0];
                $turn = $accept->handle(
                    client: $this->client,
                    channel: 'telegram',
                    body: $index === 1 ? 'Десять фотографий' : '',
                    idempotencyKey: null,
                    originExternalId: 'ten-photo-album:'.$index,
                    transportChatId: 'ten-photo-chat',
                    attachmentIds: [$attachment->getKey()],
                    mediaGroupId: 'ten-photo-album',
                    sourceOrdinal: $index,
                );
            }

            self::assertInstanceOf(CompanionTurn::class, $turn);
            self::assertSame(1, CompanionTurn::query()->where('media_group_id', 'ten-photo-album')->count());
            self::assertSame(10, $turn->fresh()->input_item_count);
            self::assertNull($turn->fresh()->input_failure_code);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_same_media_group_id_is_scoped_to_organization_and_client(): void
    {
        $accept = app(AcceptCompanionMessage::class);
        $firstAttachment = app(UploadCompanionImages::class)->handle($this->client, [$this->image('scoped-first.jpg')])[0];
        $first = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Первая организация',
            idempotencyKey: null,
            originExternalId: 'shared-media:1',
            transportChatId: 'shared-chat',
            attachmentIds: [$firstAttachment->getKey()],
            mediaGroupId: 'shared-media-group',
            sourceOrdinal: 1,
        );

        $otherOrganization = Organization::factory()->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        app(OrganizationContext::class)->set($otherOrganization);
        $secondAttachment = app(UploadCompanionImages::class)->handle($otherClient, [$this->image('scoped-second.jpg')])[0];
        $second = $accept->handle(
            client: $otherClient,
            channel: 'telegram',
            body: 'Вторая организация',
            idempotencyKey: null,
            originExternalId: 'shared-media:1',
            transportChatId: 'shared-chat',
            attachmentIds: [$secondAttachment->getKey()],
            mediaGroupId: 'shared-media-group',
            sourceOrdinal: 1,
        );

        self::assertNotSame($first->getKey(), $second->getKey());
        self::assertSame(2, CompanionTurn::query()->where('media_group_id', 'shared-media-group')->count());
        self::assertSame($this->client->getKey(), $first->fresh()->client_id);
        self::assertSame($otherClient->getKey(), $second->fresh()->client_id);
        self::assertNotSame($first->conversation_id, $second->conversation_id);
    }

    public function test_standalone_photo_and_text_can_coalesce_but_an_album_is_not_mutated_by_text(): void
    {
        $attachment = app(UploadCompanionImages::class)->handle($this->client, [$this->image('standalone.jpg')])[0];
        $accept = app(AcceptCompanionMessage::class);
        $standalone = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Фото',
            idempotencyKey: null,
            originExternalId: 'burst-chat:1',
            transportChatId: 'burst-chat',
            attachmentIds: [$attachment->getKey()],
        );
        $text = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'И вот тут болит',
            idempotencyKey: null,
            originExternalId: 'burst-chat:2',
            transportChatId: 'burst-chat',
        );
        self::assertSame($standalone->getKey(), $text->getKey());

        $album = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Альбом',
            idempotencyKey: null,
            originExternalId: 'burst-chat:3',
            transportChatId: 'burst-chat',
            mediaGroupId: 'album-200',
            sourceOrdinal: 3,
        );
        $afterAlbum = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Отдельный вопрос',
            idempotencyKey: null,
            originExternalId: 'burst-chat:4',
            transportChatId: 'burst-chat',
        );
        self::assertNotSame($album->getKey(), $afterAlbum->getKey());
    }

    public function test_invalid_image_and_over_limit_album_fail_without_silent_dropping(): void
    {
        $this->expectException(ValidationException::class);
        app(UploadCompanionImages::class)->handle(
            $this->client,
            [UploadedFile::fake()->create('fake.jpg', 2, 'image/jpeg')],
        );
    }

    public function test_exceeding_an_existing_album_marks_the_same_turn_failed_input(): void
    {
        config()->set('ai.companion.maximum_images_per_turn', 1);
        $first = app(UploadCompanionImages::class)->handle($this->client, [$this->image('limit-one.jpg')])[0];
        $second = app(UploadCompanionImages::class)->handle($this->client, [$this->image('limit-two.jpg')])[0];
        $accept = app(AcceptCompanionMessage::class);
        $turn = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Первое',
            idempotencyKey: null,
            originExternalId: 'limit-chat:1',
            transportChatId: 'limit-chat',
            attachmentIds: [$first->getKey()],
            mediaGroupId: 'album-limit',
            sourceOrdinal: 1,
        );
        $sameTurn = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Второе',
            idempotencyKey: null,
            originExternalId: 'limit-chat:2',
            transportChatId: 'limit-chat',
            attachmentIds: [$second->getKey()],
            mediaGroupId: 'album-limit',
            sourceOrdinal: 2,
        );

        self::assertSame($turn->getKey(), $sameTurn->getKey());
        self::assertSame('input_limit_exceeded', $sameTurn->fresh()->input_failure_code);
        self::assertSame(2, CompanionMessageAttachment::query()->count());
    }

    public function test_text_follow_up_keeps_semantic_history_without_resenting_the_previous_image(): void
    {
        $attachment = app(UploadCompanionImages::class)->handle($this->client, [$this->image('continuity.jpg')])[0];
        $accept = app(AcceptCompanionMessage::class);
        $imageTurn = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Вот тут болит',
            idempotencyKey: null,
            originExternalId: 'continuity-chat:1',
            transportChatId: 'continuity-chat',
            attachmentIds: [$attachment->getKey()],
        );
        $conversation = $imageTurn->conversation()->firstOrFail();
        $outbound = app(RecordCompanionMessage::class)->handle(
            organizationId: $this->organization->getKey(),
            client: $this->client,
            conversation: $conversation,
            channel: 'telegram',
            direction: ConversationDirection::Outbound,
            authorType: ConversationAuthorType::Ai,
            body: 'Предыдущий ответ по изображению.',
            contextEpoch: $imageTurn->context_epoch,
            metadata: ['message_type' => 'companion_reply', 'transport' => 'telegram'],
        );
        $imageTurn->update([
            'status' => 'completed',
            'outbound_message_id' => $outbound->getKey(),
            'completed_at' => now(),
            'burst_expires_at' => now()->subSecond(),
        ]);

        $textTurn = $accept->handle(
            client: $this->client,
            channel: 'portal',
            body: 'Это появилось вчера',
            idempotencyKey: 'continuity-text-0001',
            originExternalId: 'portal:continuity-text-0001',
        );
        $textContext = app(AssembleCompanionContext::class)->handle($this->organization->getKey(), $conversation->fresh(), $textTurn->fresh());

        self::assertSame([], $textContext['attachment_ids']);
        self::assertSame([], $textContext['required_modalities']);
        self::assertStringContainsString('Вот тут болит', $textContext['conversation_history']);
        self::assertStringContainsString('Предыдущий ответ по изображению.', $textContext['conversation_history']);

        $textTurn->update(['burst_expires_at' => now()->subSecond()]);
        $reinspect = $accept->handle(
            client: $this->client,
            channel: 'portal',
            body: 'Что справа на фото?',
            idempotencyKey: 'continuity-image-0001',
            originExternalId: 'portal:continuity-image-0001',
            imageReferenceMode: CompanionImageReferenceMode::RecentTurn,
        );
        $reinspectContext = app(AssembleCompanionContext::class)->handle($this->organization->getKey(), $conversation->fresh(), $reinspect->fresh());

        self::assertSame([$attachment->getKey()], $reinspectContext['attachment_ids']);
        self::assertSame(['image_input'], array_map(static fn ($modality): string => $modality->value, $reinspectContext['required_modalities']));

        $this->expectException(ValidationException::class);
        $accept->handle(
            client: $this->client,
            channel: 'portal',
            body: 'Проверить недоступный ответ',
            idempotencyKey: 'reinspect-invalid-source-1',
            originExternalId: 'portal:reinspect-invalid-source-1',
            imageReferenceMode: CompanionImageReferenceMode::RecentTurn,
            imageReferenceMessageId: $outbound->getKey() + 100,
        );
    }

    private function image(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 100, 100);
    }
}
