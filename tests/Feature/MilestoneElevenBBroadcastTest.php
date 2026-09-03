<?php

namespace Tests\Feature;

use App\Filament\Resources\BroadcastCampaigns\BroadcastCampaignResource;
use App\Filament\Resources\BroadcastCampaigns\Pages\CreateBroadcastCampaign as CreateBroadcastCampaignPage;
use App\Filament\Resources\BroadcastCampaigns\Pages\EditBroadcastCampaign as EditBroadcastCampaignPage;
use App\Filament\Resources\BroadcastCampaigns\Pages\ViewBroadcastCampaign as ViewBroadcastCampaignPage;
use App\Filament\Resources\ScenarioRules\Pages\CreateScenarioRule as CreateScenarioRulePage;
use App\Models\User;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Broadcasts\Application\BroadcastEligibilityPolicy;
use App\Modules\Broadcasts\Application\BroadcastMediaPreviewUrl;
use App\Modules\Broadcasts\Application\BroadcastSegmentQuery;
use App\Modules\Broadcasts\Application\CancelBroadcastCampaign;
use App\Modules\Broadcasts\Application\CopyBroadcastCampaign;
use App\Modules\Broadcasts\Application\CreateBroadcastCampaign;
use App\Modules\Broadcasts\Application\MaterializeBroadcastAudience;
use App\Modules\Broadcasts\Application\PreviewBroadcastCampaign;
use App\Modules\Broadcasts\Application\ProcessBroadcastBatch;
use App\Modules\Broadcasts\Application\ScheduleBroadcastWork;
use App\Modules\Broadcasts\Application\SetBroadcastClientClassification;
use App\Modules\Broadcasts\Application\StartBroadcastCampaign;
use App\Modules\Broadcasts\Application\TestBroadcastCampaign;
use App\Modules\Broadcasts\Application\UpdateBroadcastCampaign;
use App\Modules\Broadcasts\Domain\Contracts\BroadcastMediaStorageInterface;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Enums\BroadcastRecipientState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientTag;
use App\Modules\Broadcasts\Domain\Models\BroadcastDeliveryAttempt;
use App\Modules\Broadcasts\Domain\Models\BroadcastRecipient;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Scenarios\Application\CreateScenarioRule;
use App\Modules\Scenarios\Application\UpdateScenarioRule;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\NotificationTemplateConfiguration;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Support\RichText\RichTextDocument;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class MilestoneElevenBBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private RecordingNotificationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('queue.default', 'sync');
        $this->channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$this->channel]));
    }

    public function test_preview_is_deterministic_and_suppresses_missing_consent_or_channel(): void
    {
        [$organization, $actor] = $this->fixture();
        $eligible = $this->client($organization, consent: true, verified: true, language: 'ru');
        $this->client($organization, consent: false, verified: true, language: 'ru');
        $this->client($organization, consent: true, verified: false, language: 'ru');
        $campaign = $this->campaign($actor, [['key' => 'language', 'operator' => 'equals', 'value' => 'ru']]);

        $preview = app(PreviewBroadcastCampaign::class)->handle($actor, $campaign);

        self::assertSame('Будут выбраны клиенты, у которых язык — Русский.', $campaign->segment_summary);
        self::assertSame(3, $preview['matched']);
        self::assertSame(1, $preview['eligible']);
        self::assertSame(2, $preview['suppressed']);
        self::assertSame($eligible->getKey(), app(BroadcastSegmentQuery::class)->build($organization->getKey(), [['key' => 'language', 'operator' => 'equals', 'value' => 'ru'], ['key' => 'verified_channel', 'operator' => 'equals', 'value' => 'telegram']])->whereKey($eligible->getKey())->value('id'));
    }

    public function test_selected_client_audience_preview_shows_one_human_recipient_and_keeps_consent_safety(): void
    {
        [$organization, $actor] = $this->fixture();
        $selected = $this->client($organization, consent: true, verified: true, language: 'ru');
        $selected->forceFill(['full_name' => 'Aikhana'])->save();
        $withoutConsent = $this->client($organization, consent: false, verified: true, language: 'ru');

        $data = $this->campaignData([]);
        $data['audience_type'] = 'selected';
        $data['selected_client_ids'] = [$selected->getKey(), $withoutConsent->getKey()];
        $data['message_mode'] = 'compose';
        $data['message_body'] = 'Здравствуйте, {{ client.full_name }}!';
        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        $preview = app(PreviewBroadcastCampaign::class)->handle($actor, $campaign);

        self::assertSame('selected', $campaign->audience_type);
        self::assertSame([$selected->getKey(), $withoutConsent->getKey()], $campaign->selected_client_ids);
        self::assertSame('Выбранные клиенты: Aikhana, '.$withoutConsent->full_name, $campaign->segment_summary);
        self::assertSame(2, $preview['matched']);
        self::assertSame(1, $preview['eligible']);
        self::assertSame(1, $preview['suppressed']);
        self::assertSame(1, $preview['reasons']['marketing_suppressed']);
    }

    public function test_selected_one_client_preview_reports_the_human_recipient_count(): void
    {
        [$organization, $actor] = $this->fixture();
        $selected = $this->client($organization, consent: true, verified: true, language: 'ru');
        $selected->forceFill(['full_name' => 'Aikhana'])->save();

        $data = $this->campaignData([]);
        $data['audience_type'] = 'selected';
        $data['selected_client_ids'] = [$selected->getKey()];
        $data['message_mode'] = 'compose';
        $data['message_body'] = 'Здравствуйте, {{ client.full_name }}!';
        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        $preview = app(PreviewBroadcastCampaign::class)->handle($actor, $campaign);

        self::assertSame('Выбранные клиенты: Aikhana', $campaign->segment_summary);
        self::assertSame(1, $preview['matched']);
        self::assertSame(1, $preview['eligible']);
        self::assertSame(0, $preview['suppressed']);
    }

    public function test_direct_message_composition_is_saved_and_sent_without_template_detour(): void
    {
        [$organization, $actor] = $this->fixture();
        $client = $this->client($organization, consent: true, verified: true, language: 'ru');
        $data = $this->campaignData([]);
        $data['audience_type'] = 'selected';
        $data['selected_client_ids'] = [$client->getKey()];
        $data['message_mode'] = 'compose';
        $data['message_body'] = 'Здравствуйте, {{ client.full_name }}!';

        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        self::assertSame('compose', $campaign->message_mode);
        self::assertSame($data['message_body'], $campaign->message_body);
        self::assertNull($campaign->template_version_ru_id);
        self::assertSame(1, NotificationTemplateVersion::query()->count());

        app(StartBroadcastCampaign::class)->handle($actor, $campaign);

        self::assertSame(BroadcastCampaignState::Completed, $campaign->refresh()->state);
        self::assertCount(1, $this->channel->messages);
        self::assertSame('Здравствуйте, '.$client->full_name.'!', $this->channel->messages[0]->body);
        self::assertSame(1, BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->where('kind', 'production')->count());
    }

    public function test_direct_composition_ignores_a_legacy_template_state(): void
    {
        [$organization, $actor] = $this->fixture();
        $client = $this->client($organization, consent: true, verified: true, language: 'ru');
        $data = $this->campaignData([]);
        $data['audience_type'] = 'selected';
        $data['selected_client_ids'] = [$client->getKey()];
        $data['message_mode'] = 'compose';
        $data['message_body'] = 'Здравствуйте, {{ client.full_name }}!';
        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);
        $legacyTemplate = NotificationTemplate::factory()->forOrganization($organization)->create([
            'purpose' => ScenarioRulePurpose::Service->value,
            'locale' => 'ru',
        ]);
        $legacyVersion = NotificationTemplateVersion::factory()->forTemplate($legacyTemplate)->create();
        $campaign->forceFill(['template_version_ru_id' => $legacyVersion->getKey()])->save();

        $recipient = app(TestBroadcastCampaign::class)->handle($actor, $campaign, $client->getKey());

        self::assertSame(BroadcastRecipientState::Delivered, $recipient->state);
        self::assertSame('Здравствуйте, '.$client->full_name.'!', $this->channel->messages[0]->body);
    }

    public function test_selected_client_form_shows_the_exact_human_recipient_count(): void
    {
        [$organization, $actor] = $this->fixture();
        $client = $this->client($organization, consent: true, verified: true, language: 'ru');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($actor)
            ->test(CreateBroadcastCampaignPage::class)
            ->fillForm([
                'audience_type' => 'selected',
                'selected_client_ids' => [$client->getKey()],
            ])
            ->assertSee('Получателей: 1');
    }

    public function test_broadcast_text_limit_is_validated_at_campaign_boundary(): void
    {
        [$organization, $actor] = $this->fixture();

        $text = $this->campaignData([]);
        $text['message_mode'] = 'compose';
        $text['message_body'] = str_repeat('a', RichTextDocument::TELEGRAM_TEXT_LIMIT);
        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $text);
        self::assertSame($text['message_body'], $campaign->message_body);

        $tooLongText = $this->campaignData([]);
        $tooLongText['message_mode'] = 'compose';
        $tooLongText['message_body'] = str_repeat('a', RichTextDocument::TELEGRAM_TEXT_LIMIT + 1);
        $this->expectException(ValidationException::class);
        app(CreateBroadcastCampaign::class)->handle($actor, $tooLongText);
    }

    public function test_preview_renders_template_variables_before_the_telegram_projection(): void
    {
        [$organization, $actor] = $this->fixture();
        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['message_body'] = '<p><strong>Здравствуйте, {{ client.full_name }}!</strong> 😀</p>';
        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        $preview = app(PreviewBroadcastCampaign::class)->message($actor, $campaign);

        self::assertStringContainsString('Aikhana', $preview['bodyHtml']);
        self::assertStringNotContainsString('{{ client.full_name }}', $preview['bodyHtml']);
        self::assertStringContainsString('<b>', $preview['bodyHtml']);
    }

    public function test_campaign_preview_rejects_a_stale_message_that_exceeds_the_telegram_limit(): void
    {
        [$organization, $actor] = $this->fixture();
        $campaign = $this->campaign($actor, []);
        $campaign->forceFill([
            'message_body' => str_repeat('a', RichTextDocument::TELEGRAM_TEXT_LIMIT + 1),
        ])->save();

        $this->expectException(ValidationException::class);
        app(PreviewBroadcastCampaign::class)->message($actor, $campaign->refresh());
    }

    public function test_rendered_template_variables_are_checked_against_telegram_limits(): void
    {
        [$organization, $actor] = $this->fixture();
        $text = $this->campaignData([]);
        $text['message_mode'] = 'compose';
        $text['message_body'] = str_repeat('a', 3900).' {{ client.full_name }}';

        $this->expectException(ValidationException::class);
        app(CreateBroadcastCampaign::class)->handle($actor, $text);
    }

    public function test_photo_caption_mode_persists_caption_position_and_enforces_1024_boundary(): void
    {
        [$organization, $actor] = $this->fixture();
        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['delivery_mode'] = NotificationMessageMode::ImageWithCaption->value;
        $data['caption_position'] = 'above';
        $data['message_body'] = str_repeat('a', RichTextDocument::TELEGRAM_CAPTION_LIMIT);
        $data['media_url'] = 'https://cdn.example.test/image.jpg';

        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        self::assertSame(NotificationMessageMode::ImageWithCaption->value, $campaign->delivery_mode);
        self::assertSame('above', $campaign->caption_position);
        self::assertSame('https://cdn.example.test/image.jpg', $campaign->media['image'] ?? null);

        $tooLong = $this->campaignData([]);
        $tooLong['message_mode'] = 'compose';
        $tooLong['delivery_mode'] = NotificationMessageMode::ImageWithCaption->value;
        $tooLong['message_body'] = str_repeat('a', RichTextDocument::TELEGRAM_CAPTION_LIMIT + 1);
        $tooLong['media_url'] = 'https://cdn.example.test/image.jpg';
        $this->expectException(ValidationException::class);
        app(CreateBroadcastCampaign::class)->handle($actor, $tooLong);
    }

    public function test_broadcast_persists_multiple_photo_uploads_for_a_telegram_album(): void
    {
        [, $actor] = $this->fixture();
        Storage::fake('private');
        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['delivery_mode'] = NotificationMessageMode::Image->value;
        $data['message_body'] = '';
        $data['media_image'] = [
            UploadedFile::fake()->image('one.jpg'),
            UploadedFile::fake()->image('two.jpg'),
        ];

        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        self::assertCount(2, $campaign->media['items'] ?? []);
        self::assertSame(['photo', 'photo'], array_column($campaign->media['items'], 'type'));
    }

    public function test_broadcast_persists_a_video_upload_as_video_media(): void
    {
        [$organization, $actor] = $this->fixture();
        $storage = $this->createMock(BroadcastMediaStorageInterface::class);
        $storage->expects($this->once())
            ->method('store')
            ->willReturn('broadcast/'.$organization->getKey().'/00000000-0000-4000-8000-000000000003.mp4');
        $this->app->instance(BroadcastMediaStorageInterface::class, $storage);
        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['delivery_mode'] = NotificationMessageMode::Image->value;
        $data['message_body'] = '';
        $data['media_image'] = UploadedFile::fake()->create('video.mp4', 10, 'video/mp4');

        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        self::assertSame('video', $campaign->media['items'][0]['type'] ?? null);
        self::assertSame('broadcast/'.$organization->getKey().'/00000000-0000-4000-8000-000000000003.mp4', $campaign->media['items'][0]['source'] ?? null);
    }

    public function test_broadcast_persists_an_arbitrary_upload_as_document_media(): void
    {
        [$organization, $actor] = $this->fixture();
        $storage = $this->createMock(BroadcastMediaStorageInterface::class);
        $storage->expects($this->once())
            ->method('store')
            ->willReturn('broadcast/'.$organization->getKey().'/00000000-0000-4000-8000-000000000004.bin');
        $this->app->instance(BroadcastMediaStorageInterface::class, $storage);
        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['delivery_mode'] = NotificationMessageMode::Image->value;
        $data['message_body'] = '';
        $data['media_image'] = UploadedFile::fake()->create('terms.custom', 10, 'application/octet-stream');

        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        self::assertSame('document', $campaign->media['items'][0]['type'] ?? null);
        self::assertSame('terms.custom', $campaign->media['items'][0]['name'] ?? null);
    }

    public function test_broadcast_rejects_a_mixed_document_and_photo_album_before_persistence(): void
    {
        [, $actor] = $this->fixture();
        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['delivery_mode'] = NotificationMessageMode::Image->value;
        $data['message_body'] = '';
        $data['media'] = [
            'items' => [
                ['type' => 'document', 'source' => 'https://cdn.example.test/terms.pdf'],
                ['type' => 'photo', 'source' => 'https://cdn.example.test/photo.jpg'],
            ],
        ];

        $this->expectException(ValidationException::class);
        app(CreateBroadcastCampaign::class)->handle($actor, $data);
    }

    public function test_managed_broadcast_media_preview_uses_a_signed_organization_scoped_route(): void
    {
        [$organization, $actor] = $this->fixture();
        Storage::fake('private');
        $path = 'broadcast/'.$organization->getKey().'/00000000-0000-4000-8000-000000000005.jpg';
        Storage::disk('private')->put($path, 'managed broadcast preview');
        $campaign = $this->campaign($actor, []);
        $campaign->forceFill([
            'media' => ['items' => [['type' => 'photo', 'source' => $path, 'alt' => null, 'name' => 'preview.jpg']]],
        ])->save();
        config()->set('tenancy.default_organization_id', $organization->getKey());

        $url = app(BroadcastMediaPreviewUrl::class)->handle($campaign->refresh(), 0);
        self::assertIsString($url);

        $response = $this->actingAs($actor)->get($url);

        $response->assertOk();
        self::assertSame('managed broadcast preview', $response->streamedContent());
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));

        $otherOrganization = Organization::factory()->create();
        $otherActor = User::factory()->forOrganization($otherOrganization)->create();
        config()->set('tenancy.default_organization_id', $otherOrganization->getKey());

        $this->actingAs($otherActor)->get($url)->assertNotFound();
    }

    public function test_image_only_mode_does_not_create_or_require_a_text_template(): void
    {
        [$organization, $actor] = $this->fixture();
        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['delivery_mode'] = NotificationMessageMode::Image->value;
        $data['message_body'] = '';
        $data['media_url'] = 'https://cdn.example.test/image.jpg';

        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        self::assertNull($campaign->message_body);
        self::assertNull($campaign->template_version_ru_id);
        self::assertSame(NotificationMessageMode::Image->value, $campaign->delivery_mode);
    }

    public function test_image_mode_rejects_removing_the_only_campaign_media(): void
    {
        [$organization, $actor] = $this->fixture();
        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['message_body'] = '';
        $data['delivery_mode'] = NotificationMessageMode::Image->value;
        $data['media_url'] = 'https://cdn.example.test/image.jpg';
        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        $update = $this->campaignData([]);
        $update['name'] = 'Удаление изображения';
        $update['message_mode'] = 'compose';
        $update['message_body'] = '';
        $update['delivery_mode'] = NotificationMessageMode::Image->value;
        $update['remove_media'] = true;

        $this->expectException(ValidationException::class);
        app(UpdateBroadcastCampaign::class)->handle($actor, $campaign, $update);

        self::assertSame('https://cdn.example.test/image.jpg', $campaign->refresh()->media['image'] ?? null);
    }

    public function test_switching_to_text_allows_removing_campaign_media(): void
    {
        [$organization, $actor] = $this->fixture();
        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['message_body'] = '';
        $data['delivery_mode'] = NotificationMessageMode::Image->value;
        $data['media_url'] = 'https://cdn.example.test/image.jpg';
        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        $update = $this->campaignData([]);
        $update['name'] = 'Только текст';
        $update['message_mode'] = 'compose';
        $update['message_body'] = 'Обычный текст';
        $update['delivery_mode'] = NotificationMessageMode::Text->value;
        $update['remove_media'] = true;

        $updated = app(UpdateBroadcastCampaign::class)->handle($actor, $campaign, $update);

        self::assertNull($updated->media);
        self::assertSame(NotificationMessageMode::Text->value, $updated->delivery_mode);
    }

    public function test_image_mode_allows_replacing_campaign_media(): void
    {
        [$organization, $actor] = $this->fixture();
        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['message_body'] = '';
        $data['delivery_mode'] = NotificationMessageMode::Image->value;
        $data['media_url'] = 'https://cdn.example.test/old.jpg';
        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        $update = $this->campaignData([]);
        $update['name'] = 'Новое изображение';
        $update['message_mode'] = 'compose';
        $update['message_body'] = '';
        $update['delivery_mode'] = NotificationMessageMode::Image->value;
        $update['media_url'] = 'https://cdn.example.test/new.jpg';

        $updated = app(UpdateBroadcastCampaign::class)->handle($actor, $campaign, $update);

        self::assertSame('https://cdn.example.test/new.jpg', $updated->media['image'] ?? null);
        self::assertSame(NotificationMessageMode::Image->value, $updated->delivery_mode);
    }

    public function test_image_delivery_cannot_launch_when_persisted_media_is_absent(): void
    {
        [$organization, $actor] = $this->fixture();
        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['message_body'] = '';
        $data['delivery_mode'] = NotificationMessageMode::Image->value;
        $data['media_url'] = 'https://cdn.example.test/image.jpg';
        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);
        $campaign->forceFill(['media' => null])->save();

        try {
            app(StartBroadcastCampaign::class)->handle($actor, $campaign);
            self::fail('Image delivery without media must be rejected before dispatch.');
        } catch (ValidationException $exception) {
            self::assertSame('Добавьте медиа или выберите текстовый режим.', $exception->errors()['media_image'][0]);
        }

        self::assertSame(BroadcastCampaignState::Draft, $campaign->refresh()->state);
        self::assertNull($campaign->audience_snapshot_id);
    }

    public function test_managed_campaign_media_is_streamed_to_the_channel_after_delivery_claim(): void
    {
        [$organization, $actor] = $this->fixture();
        $this->client($organization, consent: true, verified: true, language: 'ru');
        Storage::fake('public');
        $path = 'content/'.$organization->getKey().'/00000000-0000-4000-8000-000000000001.jpg';
        $contents = 'managed broadcast image';
        Storage::disk('public')->put($path, $contents);

        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['delivery_mode'] = NotificationMessageMode::ImageWithCaption->value;
        $data['message_body'] = '<p>Подпись</p>';
        $data['media'] = ['image' => $path, 'alt' => null];
        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        app(StartBroadcastCampaign::class)->handle($actor, $campaign);

        self::assertCount(1, $this->channel->messages);
        $stream = $this->channel->messages[0]->mediaStream;
        self::assertIsResource($stream);
        self::assertSame($contents, stream_get_contents($stream));
        fclose($stream);
    }

    public function test_missing_managed_campaign_media_is_recorded_before_channel_delivery(): void
    {
        [$organization, $actor] = $this->fixture();
        $this->client($organization, consent: true, verified: true, language: 'ru');
        Storage::fake('public');
        $path = 'content/'.$organization->getKey().'/00000000-0000-4000-8000-000000000002.jpg';

        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['delivery_mode'] = NotificationMessageMode::Image->value;
        $data['message_body'] = '';
        $data['media'] = ['image' => $path, 'alt' => null];
        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        app(StartBroadcastCampaign::class)->handle($actor, $campaign);

        self::assertCount(0, $this->channel->messages);
        self::assertSame(
            'media_unavailable',
            BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->where('kind', 'production')->sole()->last_error_code,
        );
    }

    public function test_sent_campaign_can_only_be_copied_to_a_new_draft(): void
    {
        [$organization, $actor] = $this->fixture();
        $data = $this->campaignData([]);
        $data['message_mode'] = 'compose';
        $data['message_body'] = '<p><strong>Повтор</strong> 😀</p>';
        $data['delivery_mode'] = NotificationMessageMode::ImageThenText->value;
        $data['media_url'] = 'https://cdn.example.test/image.jpg';
        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);
        $campaign->forceFill([
            'state' => BroadcastCampaignState::Completed,
            'completed_at' => now(),
        ])->save();

        $copy = app(CopyBroadcastCampaign::class)->handle($actor, $campaign);

        self::assertNotSame($campaign->getKey(), $copy->getKey());
        self::assertSame('Проверочная рассылка — повтор', $copy->name);
        self::assertSame(BroadcastCampaignState::Completed, $campaign->refresh()->state);
        self::assertSame(BroadcastCampaignState::Draft, $copy->state);
        self::assertSame($campaign->message_body, $copy->message_body);
        self::assertSame($campaign->delivery_mode, $copy->delivery_mode);
        self::assertSame($campaign->caption_position, $copy->caption_position);
        self::assertSame($campaign->media, $copy->media);
        self::assertNull($copy->audience_snapshot_id);
        self::assertSame(0, $copy->audience_count);

        $copy->forceFill([
            'state' => BroadcastCampaignState::Completed,
            'completed_at' => now(),
        ])->save();
        $secondCopy = app(CopyBroadcastCampaign::class)->handle($actor, $copy);

        self::assertSame('Проверочная рассылка — повтор 2', $secondCopy->name);
    }

    public function test_run_again_starts_a_new_campaign_and_opens_its_view(): void
    {
        [$organization, $actor] = $this->fixture();
        $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, []);
        $campaign->forceFill([
            'state' => BroadcastCampaignState::Completed,
            'completed_at' => now(),
        ])->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($actor)
            ->test(ViewBroadcastCampaignPage::class, ['record' => $campaign->getKey()])
            ->callAction('runAgain')
            ->assertNotified('Рассылка отправлена')
            ->assertRedirect(BroadcastCampaignResource::getUrl('view', ['record' => BroadcastCampaign::query()->latest('id')->firstOrFail()]));

        $copy = BroadcastCampaign::query()->where('id', '!=', $campaign->getKey())->latest('id')->firstOrFail();

        self::assertSame(BroadcastCampaignState::Completed, $copy->state);
        self::assertSame(1, BroadcastRecipient::query()->where('campaign_id', $copy->getKey())->where('kind', 'production')->count());
        self::assertSame(BroadcastCampaignState::Completed, $campaign->refresh()->state);
    }

    public function test_edit_and_rerun_keeps_the_copy_as_a_draft(): void
    {
        [$organization, $actor] = $this->fixture();
        $campaign = $this->campaign($actor, []);
        $campaign->forceFill([
            'state' => BroadcastCampaignState::Completed,
            'completed_at' => now(),
        ])->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($actor)
            ->test(ViewBroadcastCampaignPage::class, ['record' => $campaign->getKey()])
            ->callAction('editAndRerun');
        $copy = BroadcastCampaign::query()->where('id', '!=', $campaign->getKey())->latest('id')->firstOrFail();

        $component->assertRedirect(BroadcastCampaignResource::getUrl('edit', ['record' => $copy]));
        self::assertSame(BroadcastCampaignState::Draft, $copy->state);
        self::assertSame(0, BroadcastRecipient::query()->where('campaign_id', $copy->getKey())->count());
    }

    public function test_editing_a_draft_redirects_to_its_saved_view(): void
    {
        [$organization, $actor] = $this->fixture();
        $campaign = $this->campaign($actor, []);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($actor)
            ->test(EditBroadcastCampaignPage::class, ['record' => $campaign->getKey()])
            ->fillForm(['name' => 'Сохранённая рассылка'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('Рассылка сохранена')
            ->assertRedirect(BroadcastCampaignResource::getUrl('view', ['record' => $campaign]));

        self::assertSame('Сохранённая рассылка', $campaign->refresh()->name);
    }

    public function test_immediate_send_materializes_once_batches_and_replay_does_not_redeliver(): void
    {
        [$organization, $actor] = $this->fixture();
        foreach (range(1, 205) as $index) {
            $this->client($organization, consent: true, verified: true, language: 'ru');
        }
        $campaign = $this->campaign($actor, []);

        app(StartBroadcastCampaign::class)->handle($actor, $campaign);

        self::assertSame(BroadcastCampaignState::Completed, $campaign->refresh()->state);
        self::assertSame(205, BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->where('kind', 'production')->count());
        self::assertSame(3, DB::table('broadcast_batches')->where('campaign_id', $campaign->getKey())->count());
        self::assertCount(205, $this->channel->messages);
        app(ScheduleBroadcastWork::class)->handle();
        self::assertCount(205, $this->channel->messages);
        self::assertSame(205, DB::table('broadcast_delivery_attempts')->count());
    }

    public function test_snapshot_remains_unchanged_after_client_and_campaign_source_data_changes(): void
    {
        [$organization, $actor] = $this->fixture();
        $client = $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, [['key' => 'language', 'operator' => 'equals', 'value' => 'ru']], 'scheduled', now()->addHour());

        app(StartBroadcastCampaign::class)->handle($actor, $campaign);
        $recipient = BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->sole();
        $client->forceFill(['full_name' => 'Новое имя', 'language' => 'en'])->save();

        self::assertSame('ru', $recipient->refresh()->language);
        self::assertNotSame('Новое имя', $recipient->render_context['client']['full_name']);
        self::assertSame(1, $campaign->refresh()->audience_count);
    }

    public function test_test_send_targets_only_explicit_recipient_and_is_distinguishable(): void
    {
        [$organization, $actor] = $this->fixture();
        $target = $this->client($organization, consent: true, verified: true, language: 'ru');
        $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, []);

        $recipient = app(TestBroadcastCampaign::class)->handle($actor, $campaign, $target->getKey());

        self::assertSame('test', $recipient->kind);
        self::assertSame(BroadcastRecipientState::Delivered, $recipient->state);
        self::assertSame($target->getKey(), $recipient->client_id);
        self::assertCount(1, $this->channel->messages);
        self::assertSame(0, BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->where('kind', 'production')->count());
    }

    public function test_test_recipient_selector_excludes_clients_without_current_marketing_consent(): void
    {
        [$organization, $actor] = $this->fixture();
        $eligible = $this->client($organization, consent: true, verified: true, language: 'ru');
        $withoutConsent = $this->client($organization, consent: false, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, []);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($actor)
            ->test(ViewBroadcastCampaignPage::class, ['record' => $campaign->getKey()])
            ->mountAction('test');
        $select = $component->instance()->getSchemaComponent('mountedActionSchema0.test_client_id');

        self::assertInstanceOf(Select::class, $select);
        self::assertArrayHasKey($eligible->getKey(), $select->getOptions());
        self::assertArrayNotHasKey($withoutConsent->getKey(), $select->getOptions());
    }

    public function test_test_send_explains_missing_marketing_consent(): void
    {
        [$organization, $actor] = $this->fixture();
        $target = $this->client($organization, consent: true, verified: true, language: 'ru');
        ClientConsent::query()->where('client_id', $target->getKey())->delete();
        $campaign = $this->campaign($actor, []);

        try {
            app(TestBroadcastCampaign::class)->handle($actor, $campaign, $target->getKey());
            self::fail('An ineligible test recipient must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'У тестового получателя нет согласия на маркетинговые сообщения.',
                $exception->errors()['test_client_id'][0],
            );
        }
    }

    public function test_test_send_action_shows_validation_failures_as_notifications(): void
    {
        [$organization, $actor] = $this->fixture();
        $target = $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, []);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($actor)
            ->test(ViewBroadcastCampaignPage::class, ['record' => $campaign->getKey()])
            ->mountAction('test')
            ->setActionData(['test_client_id' => $target->getKey()]);
        $campaign->forceFill(['channel_priority' => ['email']])->save();

        $component
            ->callMountedAction()
            ->assertNotified('Тестовая отправка не выполнена');

        self::assertSame(0, BroadcastRecipient::query()->count());
    }

    public function test_language_referral_source_booking_last_visit_no_rebooking_tags_and_survey_completion_queries_are_scoped(): void
    {
        [$organization] = $this->fixture();
        $foreign = Organization::factory()->create();
        $client = $this->client($organization, consent: true, verified: true, language: 'en');
        $referrer = Client::factory()->forOrganization($organization)->create();
        BroadcastClientTag::query()->create(['organization_id' => $organization->getKey(), 'client_id' => $client->getKey(), 'tag' => 'vip']);
        ReferralRelationship::query()->forceCreate(['organization_id' => $organization->getKey(), 'referrer_client_id' => $referrer->getKey(), 'referred_client_id' => $client->getKey(), 'establishment_method' => 'manual_crm', 'registered_at' => now()]);
        ClientAttribution::query()->forceCreate(['organization_id' => $organization->getKey(), 'client_id' => $client->getKey(), 'source_type' => 'utm', 'utm_source' => 'newsletter', 'capture_channel' => 'portal', 'captured_at' => now(), 'accepted_at' => now()]);
        $this->completedBooking($organization, $client, now()->subMonth());
        $this->surveyCompletion($organization, $client);
        $foreignClient = $this->client($foreign, consent: true, verified: true, language: 'en');
        BroadcastClientTag::query()->create(['organization_id' => $foreign->getKey(), 'client_id' => $foreignClient->getKey(), 'tag' => 'vip']);
        $filters = [['key' => 'tag', 'operator' => 'equals', 'value' => 'vip'], ['key' => 'language', 'operator' => 'equals', 'value' => 'en'], ['key' => 'referral_relationship', 'operator' => 'equals', 'value' => true], ['key' => 'attribution_source', 'operator' => 'equals', 'value' => 'newsletter'], ['key' => 'visit_count', 'operator' => 'gte', 'value' => 1], ['key' => 'last_visit', 'operator' => 'before', 'value' => now()->subWeek()->toIso8601String()], ['key' => 'no_future_booking', 'operator' => 'equals', 'value' => true], ['key' => 'survey_completed', 'operator' => 'equals', 'value' => true]];

        self::assertSame([$client->getKey()], app(BroadcastSegmentQuery::class)->build($organization->getKey(), $filters)->pluck('id')->all());
    }

    public function test_cross_organization_and_staff_mutations_are_denied(): void
    {
        [$organization, $owner] = $this->fixture();
        $campaign = $this->campaign($owner, []);
        $other = Organization::factory()->create();
        $otherOwner = User::factory()->forOrganization($other)->create();
        app(OrganizationContext::class)->set($other);

        try {
            app(PreviewBroadcastCampaign::class)->handle($otherOwner, $campaign);
            self::fail('Cross-organization campaign access must fail.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('audit_events')->where('organization_id', $other->getKey())->where('action', 'broadcast.campaign.previewed')->count());
        }

        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        app(OrganizationContext::class)->set($organization);
        $this->expectException(AuthorizationException::class);
        app(CreateBroadcastCampaign::class)->handle($staff, $this->campaignData([]));
    }

    public function test_explicit_withdrawal_and_unverified_channel_never_dispatch(): void
    {
        [$organization, $actor] = $this->fixture();
        $client = $this->client($organization, consent: true, verified: true, language: 'ru');
        ClientConsent::factory()->forClient($client)->create(['subject' => ConsentSubject::Marketing->value, 'is_required' => false, 'granted' => false, 'recorded_at' => now()->addSecond()]);
        $campaign = $this->campaign($actor, []);

        app(StartBroadcastCampaign::class)->handle($actor, $campaign);

        self::assertCount(0, $this->channel->messages);
        $recipient = BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->sole();
        self::assertSame(BroadcastRecipientState::Suppressed, $recipient->state);
        self::assertSame([], $recipient->render_context);
    }

    public function test_withdrawal_after_snapshot_is_rechecked_before_delivery(): void
    {
        [$organization, $actor] = $this->fixture();
        $client = $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, []);
        app(MaterializeBroadcastAudience::class)->handle($campaign);
        ClientConsent::factory()->forClient($client)->create([
            'subject' => ConsentSubject::Marketing->value,
            'is_required' => false,
            'granted' => false,
            'recorded_at' => now()->addSecond(),
        ]);
        $campaign->forceFill(['state' => BroadcastCampaignState::Dispatching, 'dispatch_started_at' => now()])->save();
        $batchId = (int) DB::table('broadcast_batches')->where('campaign_id', $campaign->getKey())->value('id');

        app(ProcessBroadcastBatch::class)->handle($organization->getKey(), $batchId);

        $recipient = BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->sole();
        self::assertSame(BroadcastRecipientState::Suppressed, $recipient->state);
        self::assertSame('marketing_suppressed', $recipient->exclusion_code);
        self::assertSame([], $recipient->render_context);
        self::assertCount(0, $this->channel->messages);
    }

    public function test_cancelling_scheduled_campaign_closes_unfinished_work_and_prevents_later_dispatch(): void
    {
        [$organization, $actor] = $this->fixture();
        $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, [], 'scheduled', now()->addHour());
        app(MaterializeBroadcastAudience::class)->handle($campaign);
        $campaign->forceFill(['state' => BroadcastCampaignState::Scheduled])->save();

        app(CancelBroadcastCampaign::class)->handle($actor, $campaign);
        app(ScheduleBroadcastWork::class)->handle();

        $recipient = BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->sole();
        self::assertSame(BroadcastCampaignState::Cancelled, $campaign->refresh()->state);
        self::assertSame(BroadcastRecipientState::Failed, $recipient->state);
        self::assertSame('campaign_cancelled', $recipient->last_error_code);
        self::assertSame([], $recipient->render_context);
        self::assertSame('failed', DB::table('broadcast_batches')->where('campaign_id', $campaign->getKey())->value('state'));
        self::assertCount(0, $this->channel->messages);
    }

    public function test_sensitive_segment_key_is_rejected_independently(): void
    {
        [, $actor] = $this->fixture();
        $data = $this->campaignData([['key' => 'medical.diagnosis', 'operator' => 'equals', 'value' => 'x']]);

        $this->expectException(ValidationException::class);
        app(CreateBroadcastCampaign::class)->handle($actor, $data);
    }

    public function test_unknown_survey_result_segment_key_is_rejected_independently(): void
    {
        [, $actor] = $this->fixture();
        $data = $this->campaignData([['key' => 'survey.result_category', 'operator' => 'equals', 'value' => 'x']]);

        $this->expectException(ValidationException::class);
        app(CreateBroadcastCampaign::class)->handle($actor, $data);
    }

    public function test_non_marketing_template_is_rejected_independently(): void
    {
        [$organization, $actor] = $this->fixture();
        $template = NotificationTemplate::factory()->forOrganization($organization)->create(['purpose' => ScenarioRulePurpose::Service->value, 'locale' => 'ru']);
        $version = NotificationTemplateVersion::factory()->forTemplate($template)->create();
        $data = $this->campaignData([]);
        $data['template_version_ru_id'] = $version->getKey();

        $this->expectException(ValidationException::class);
        app(CreateBroadcastCampaign::class)->handle($actor, $data);
    }

    public function test_crm_pages_are_authorized_and_cross_organization_records_are_hidden(): void
    {
        [$organization, $owner] = $this->fixture();
        $campaign = $this->campaign($owner, []);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get(BroadcastCampaignResource::getUrl('index'))->assertOk();
        $this->get(BroadcastCampaignResource::getUrl('create'))->assertOk();

        $other = Organization::factory()->create();
        $otherOwner = User::factory()->forOrganization($other)->create();
        config()->set('tenancy.default_organization_id', $other->getKey());
        app(OrganizationContext::class)->set($other);
        $this->actingAs($otherOwner);
        $this->get(BroadcastCampaignResource::getUrl('view', ['record' => $campaign]))->assertNotFound();
    }

    public function test_delivery_errors_are_sanitized_and_horizon_consumes_the_bounded_queue(): void
    {
        [$organization, $actor] = $this->fixture();
        $this->client($organization, consent: true, verified: true, language: 'ru');
        $this->channel = new RecordingNotificationChannel('telegram', NotificationDeliveryResult::permanentFailure('Bearer secret token'));
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$this->channel]));
        $campaign = $this->campaign($actor, []);

        app(StartBroadcastCampaign::class)->handle($actor, $campaign);

        $recipient = BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->sole();
        self::assertSame(BroadcastRecipientState::Failed, $recipient->state);
        self::assertSame('provider_error', $recipient->last_error_code);
        self::assertNotContains('Bearer secret token', DB::table('audit_events')->pluck('metadata')->all());
        self::assertContains('broadcasts', config('horizon.defaults.supervisor-1.queue'));
    }

    public function test_delivery_attempt_is_persisted_before_provider_call_and_ambiguous_outcome_is_terminal(): void
    {
        [$organization, $actor] = $this->fixture();
        $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, []);
        $seenBeforeCall = null;
        $this->channel->onSend = function () use (&$seenBeforeCall): void {
            $seenBeforeCall = BroadcastDeliveryAttempt::query()->sole()->outcome;
        };

        app(StartBroadcastCampaign::class)->handle($actor, $campaign);

        self::assertSame(NotificationDeliveryOutcome::InFlight, $seenBeforeCall);
        self::assertSame(NotificationDeliveryOutcome::Delivered, BroadcastDeliveryAttempt::query()->sole()->outcome);

        $this->channel = new RecordingNotificationChannel;
        $this->channel->throwAfterSend = true;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$this->channel]));
        $secondCampaign = $this->campaign($actor, []);
        app(StartBroadcastCampaign::class)->handle($actor, $secondCampaign);
        $recipient = BroadcastRecipient::query()->where('campaign_id', $secondCampaign->getKey())->sole();

        self::assertSame(BroadcastRecipientState::Failed, $recipient->state);
        self::assertSame('delivery_outcome_unknown', $recipient->last_error_code);
        self::assertSame('unknown', BroadcastDeliveryAttempt::query()->where('recipient_id', $recipient->getKey())->sole()->outcome->value);
        self::assertCount(1, $this->channel->messages);
        app(ScheduleBroadcastWork::class)->handle();
        self::assertCount(1, $this->channel->messages);
    }

    public function test_definite_pre_send_failure_is_bounded_retryable_and_suppressed_context_is_minimized(): void
    {
        [$organization, $actor] = $this->fixture();
        $suppressed = $this->client($organization, consent: false, verified: true, language: 'ru');
        $eligible = $this->client($organization, consent: true, verified: true, language: 'ru');
        $this->channel->throwBeforeSend = true;
        $campaign = $this->campaign($actor, []);
        $snapshot = app(MaterializeBroadcastAudience::class)->handle($campaign);

        $suppressedRecipient = BroadcastRecipient::query()->where('snapshot_id', $snapshot->getKey())->where('client_id', $suppressed->getKey())->sole();
        self::assertSame([], $suppressedRecipient->render_context);

        $campaign->forceFill(['state' => BroadcastCampaignState::Dispatching, 'dispatch_started_at' => now()])->save();
        $batchId = (int) DB::table('broadcast_batches')->where('campaign_id', $campaign->getKey())->value('id');
        app(ProcessBroadcastBatch::class)->handle($organization->getKey(), $batchId);
        $recipient = BroadcastRecipient::query()->where('client_id', $eligible->getKey())->where('campaign_id', $campaign->getKey())->sole();

        self::assertSame(BroadcastRecipientState::Pending, $recipient->state);
        self::assertSame('retryable', BroadcastDeliveryAttempt::query()->where('recipient_id', $recipient->getKey())->sole()->outcome->value);
        self::assertCount(0, $this->channel->messages);
    }

    public function test_deactivated_marketing_template_stops_delivery_after_snapshot(): void
    {
        [$organization, $actor] = $this->fixture();
        $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, []);
        app(MaterializeBroadcastAudience::class)->handle($campaign);
        $templateId = $campaign->template_version_ru_id;
        $template = NotificationTemplateVersion::query()->findOrFail($templateId)->template;
        self::assertNotNull($template);
        $template->forceFill(['is_active' => false])->save();
        $campaign->forceFill(['state' => BroadcastCampaignState::Dispatching, 'dispatch_started_at' => now()])->save();
        $batchId = (int) DB::table('broadcast_batches')->where('campaign_id', $campaign->getKey())->value('id');

        app(ProcessBroadcastBatch::class)->handle($organization->getKey(), $batchId);

        $recipient = BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->sole();
        self::assertSame(BroadcastRecipientState::Failed, $recipient->state);
        self::assertSame('template_inactive_or_wrong_purpose', $recipient->last_error_code);
        self::assertCount(0, $this->channel->messages);
    }

    public function test_stale_snapshot_revision_cannot_start(): void
    {
        [$organization, $actor] = $this->fixture();
        $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, []);
        app(MaterializeBroadcastAudience::class)->handle($campaign);
        $campaign->forceFill(['draft_version' => 2])->save();

        $this->expectException(ValidationException::class);
        app(StartBroadcastCampaign::class)->handle($actor, $campaign->refresh());
    }

    public function test_scheduler_cannot_launch_a_snapshot_from_an_older_draft_revision(): void
    {
        [$organization, $actor] = $this->fixture();
        $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, []);
        app(MaterializeBroadcastAudience::class)->handle($campaign);
        $campaign->forceFill([
            'draft_version' => 2,
            'state' => BroadcastCampaignState::Scheduled,
            'scheduled_at' => now()->subMinute(),
        ])->save();

        app(ScheduleBroadcastWork::class)->handle();

        self::assertSame(BroadcastCampaignState::Cancelled, $campaign->refresh()->state);
        self::assertSame('snapshot_superseded', $campaign->last_dispatch_error_code);
        self::assertSame('failed', DB::table('broadcast_batches')->where('campaign_id', $campaign->getKey())->value('state'));
        self::assertCount(0, $this->channel->messages);
    }

    public function test_draft_update_supersedes_old_snapshot_and_cannot_launch_old_batches(): void
    {
        [$organization, $actor] = $this->fixture();
        $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, []);
        $oldSnapshot = app(MaterializeBroadcastAudience::class)->handle($campaign);
        $oldBatchId = (int) DB::table('broadcast_batches')->where('snapshot_id', $oldSnapshot->getKey())->value('id');
        $data = [
            'name' => 'Обновлённая рассылка',
            'send_mode' => 'immediate',
            'channel_priority' => ['telegram'],
            'segment_definition' => [],
            'template_version_ru_id' => $campaign->template_version_ru_id,
            'template_version_en_id' => null,
            'scheduled_at' => null,
        ];

        $updated = app(UpdateBroadcastCampaign::class)->handle($actor, $campaign, $data);

        self::assertNull($updated->audience_snapshot_id);
        self::assertSame('failed', DB::table('broadcast_batches')->where('id', $oldBatchId)->value('state'));
        self::assertSame('snapshot_superseded', BroadcastRecipient::query()->where('snapshot_id', $oldSnapshot->getKey())->sole()->last_error_code);

        app(StartBroadcastCampaign::class)->handle($actor, $updated);

        self::assertSame(2, (int) $updated->refresh()->draft_version);
        self::assertSame(1, BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->where('kind', 'production')->where('state', BroadcastRecipientState::Delivered->value)->count());
        self::assertCount(1, $this->channel->messages);
    }

    public function test_tag_assignment_and_segment_query_use_canonical_case(): void
    {
        [$organization, $actor] = $this->fixture();
        $client = $this->client($organization, consent: true, verified: true, language: 'ru');
        app(SetBroadcastClientClassification::class)->handle($actor, $client, null, [' VIP ']);

        $ids = app(BroadcastSegmentQuery::class)->build($organization->getKey(), [['key' => 'tag', 'operator' => 'equals', 'value' => 'VIP']])->pluck('id')->all();

        self::assertSame([$client->getKey()], $ids);
        self::assertSame('vip', BroadcastClientTag::query()->where('client_id', $client->getKey())->value('tag'));
    }

    public function test_equal_timestamp_marketing_consent_order_is_deterministic(): void
    {
        [$organization] = $this->fixture();
        $withdrawn = Client::factory()->forOrganization($organization)->create(['language' => 'ru']);
        ClientChannelIdentity::factory()->forClient($withdrawn)->create(['channel' => 'telegram', 'external_id' => 'equal-time-withdrawn', 'verification_status' => ChannelIdentityStatus::Verified->value, 'verification_method' => 'test', 'verified_at' => now()]);
        $timestamp = now()->startOfSecond();
        ClientConsent::factory()->forClient($withdrawn)->create(['subject' => ConsentSubject::Marketing->value, 'is_required' => false, 'granted' => true, 'recorded_at' => $timestamp]);
        ClientConsent::factory()->forClient($withdrawn)->create(['subject' => ConsentSubject::Marketing->value, 'is_required' => false, 'granted' => false, 'recorded_at' => $timestamp]);
        self::assertFalse(app(BroadcastEligibilityPolicy::class)->evaluate($withdrawn, $organization->getKey(), ['telegram'])['eligible']);

        $granted = Client::factory()->forOrganization($organization)->create(['language' => 'ru']);
        ClientChannelIdentity::factory()->forClient($granted)->create(['channel' => 'telegram', 'external_id' => 'equal-time-granted', 'verification_status' => ChannelIdentityStatus::Verified->value, 'verification_method' => 'test', 'verified_at' => now()]);
        ClientConsent::factory()->forClient($granted)->create(['subject' => ConsentSubject::Marketing->value, 'is_required' => false, 'granted' => false, 'recorded_at' => $timestamp]);
        ClientConsent::factory()->forClient($granted)->create(['subject' => ConsentSubject::Marketing->value, 'is_required' => false, 'granted' => true, 'recorded_at' => $timestamp]);
        self::assertTrue(app(BroadcastEligibilityPolicy::class)->evaluate($granted, $organization->getKey(), ['telegram'])['eligible']);
    }

    public function test_organization_timezone_is_used_for_scheduled_campaign_input(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $actor = User::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        $wallClock = CarbonImmutable::now('Asia/Almaty')->addDay()->setTime(10, 30)->format('Y-m-d H:i');
        $data = $this->campaignData([]);
        $data['send_mode'] = 'scheduled';
        $data['scheduled_at'] = $wallClock;

        $campaign = app(CreateBroadcastCampaign::class)->handle($actor, $data);

        $scheduledAt = $campaign->scheduled_at;
        self::assertNotNull($scheduledAt);
        self::assertTrue($scheduledAt->equalTo(CarbonImmutable::parse($wallClock, 'Asia/Almaty')->utc()));
    }

    public function test_scheduled_campaign_rejects_invalid_or_past_wall_clock_values(): void
    {
        [$organization, $actor] = $this->fixture();
        $data = $this->campaignData([]);
        $data['send_mode'] = 'scheduled';
        $data['scheduled_at'] = 'not-a-date';

        try {
            app(CreateBroadcastCampaign::class)->handle($actor, $data);
            self::fail('Invalid wall-clock values must be rejected.');
        } catch (ValidationException) {
            self::assertSame(0, BroadcastCampaign::query()->count());
        }

        $data['scheduled_at'] = CarbonImmutable::now($organization->defaultTimezone())->subMinute()->format('Y-m-d H:i');
        $this->expectException(ValidationException::class);
        app(CreateBroadcastCampaign::class)->handle($actor, $data);
    }

    public function test_persisted_unknown_segment_definition_fails_closed(): void
    {
        [$organization, $actor] = $this->fixture();
        $campaign = $this->campaign($actor, []);
        $campaign->forceFill(['segment_definition' => [['key' => 'unknown.persisted', 'operator' => 'equals', 'value' => 'x']]])->save();

        $this->expectException(ValidationException::class);
        app(BroadcastSegmentQuery::class)->build($organization->getKey(), $campaign->refresh()->segment_definition);
    }

    public function test_marketing_template_variables_are_limited_to_broadcast_context(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NotificationTemplateConfiguration::from([
            'template_key' => 'marketing-variable-test',
            'name' => 'Marketing variable test',
            'locale' => 'ru',
            'purpose' => 'marketing',
            'is_active' => true,
            'body' => 'Запись {{ booking.starts_at }}',
            'variables' => ['booking.starts_at'],
        ]);
    }

    public function test_marketing_template_cannot_enter_m5_scenario_create_or_update_paths(): void
    {
        [$organization, $actor] = $this->fixture();
        $marketing = NotificationTemplate::factory()->forOrganization($organization)->create(['purpose' => ScenarioRulePurpose::Marketing->value]);
        $marketingVersion = NotificationTemplateVersion::factory()->forTemplate($marketing)->create();
        $service = NotificationTemplate::factory()->forOrganization($organization)->create(['purpose' => ScenarioRulePurpose::Service->value]);
        $serviceVersion = NotificationTemplateVersion::factory()->forTemplate($service)->create();
        $attributes = [
            'rule_key' => 'broadcast-not-scenario',
            'name' => 'Broadcast not scenario',
            'trigger_event' => 'booking.completed',
            'is_enabled' => true,
            'delay_value' => 0,
            'delay_unit' => 'minutes',
            'purpose' => 'service',
            'conditions' => [],
            'recipient_strategy' => ['type' => 'client'],
            'channel_priority' => ['telegram'],
            'template_version_id' => $marketingVersion->getKey(),
        ];

        try {
            app(CreateScenarioRule::class)->handle($actor, $attributes);
            self::fail('A marketing template must not be accepted by an M5 scenario rule.');
        } catch (ValidationException) {
            self::assertSame(0, ScenarioRule::query()->count());
        }

        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($serviceVersion)->create();
        $this->expectException(ValidationException::class);
        app(UpdateScenarioRule::class)->handle($actor, $rule, [...$attributes, 'rule_key' => $rule->rule_key, 'template_version_id' => $marketingVersion->getKey()]);
    }

    public function test_scenario_rule_template_choices_exclude_marketing_templates(): void
    {
        [$organization, $actor] = $this->fixture();
        $marketing = NotificationTemplate::factory()->forOrganization($organization)->create(['purpose' => ScenarioRulePurpose::Marketing->value, 'locale' => 'ru']);
        $marketingVersion = NotificationTemplateVersion::factory()->forTemplate($marketing)->create();
        $service = NotificationTemplate::factory()->forOrganization($organization)->create(['purpose' => ScenarioRulePurpose::Service->value, 'locale' => 'ru']);
        $serviceVersion = NotificationTemplateVersion::factory()->forTemplate($service)->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($actor)->test(CreateScenarioRulePage::class);
        $component->fillForm(['purpose' => ScenarioRulePurpose::Service->value]);
        $select = $component->instance()->getSchemaComponent('form.template_version_id');

        self::assertInstanceOf(Select::class, $select);
        self::assertArrayHasKey($serviceVersion->getKey(), $select->getOptions());
        self::assertArrayNotHasKey($marketingVersion->getKey(), $select->getOptions());
    }

    public function test_broadcast_template_choices_exclude_unsupported_marketing_variables(): void
    {
        [$organization, $actor] = $this->fixture();
        $unsupported = NotificationTemplate::factory()->forOrganization($organization)->create([
            'purpose' => ScenarioRulePurpose::Marketing->value,
            'locale' => 'ru',
        ]);
        $unsupportedVersion = NotificationTemplateVersion::factory()->forTemplate($unsupported)->create([
            'body' => 'Запись {{ booking.starts_at }}',
            'variables' => ['booking.starts_at'],
        ]);
        $supported = NotificationTemplate::factory()->forOrganization($organization)->create([
            'purpose' => ScenarioRulePurpose::Marketing->value,
            'locale' => 'ru',
        ]);
        $supportedVersion = NotificationTemplateVersion::factory()->forTemplate($supported)->create([
            'body' => 'Здравствуйте, {{ client.full_name }}!',
            'variables' => ['client.full_name'],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($actor)->test(CreateBroadcastCampaignPage::class);
        $component->fillForm(['message_mode' => 'saved_template']);
        $select = $component->instance()->getSchemaComponent('form.template_version_ru_id');

        self::assertInstanceOf(Select::class, $select);
        self::assertArrayHasKey($supportedVersion->getKey(), $select->getOptions());
        self::assertArrayNotHasKey($unsupportedVersion->getKey(), $select->getOptions());
    }

    public function test_revoked_creator_authority_blocks_scheduled_execution(): void
    {
        [$organization, $actor] = $this->fixture();
        $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, [], 'scheduled', now()->addHour());
        app(MaterializeBroadcastAudience::class)->handle($campaign);
        $campaign->forceFill(['state' => BroadcastCampaignState::Scheduled, 'scheduled_at' => now()->subMinute()])->save();
        $membership = $actor->membershipFor($organization);
        self::assertNotNull($membership);
        $membership->forceFill(['is_active' => false])->save();

        app(ScheduleBroadcastWork::class)->handle();

        self::assertSame(BroadcastCampaignState::Cancelled, $campaign->refresh()->state);
        self::assertCount(0, $this->channel->messages);
        self::assertSame(BroadcastRecipientState::Failed, BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->sole()->state);
        self::assertSame('authorization_revoked', BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->sole()->last_error_code);
        self::assertSame(1, DB::table('audit_events')->where('action', 'broadcast.campaign.execution_blocked')->count());
    }

    public function test_failed_test_send_is_not_a_success_audit(): void
    {
        [$organization, $actor] = $this->fixture();
        $target = $this->client($organization, consent: true, verified: true, language: 'ru');
        $this->channel = new RecordingNotificationChannel('telegram', NotificationDeliveryResult::permanentFailure('provider rejected'));
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$this->channel]));
        $campaign = $this->campaign($actor, []);

        app(TestBroadcastCampaign::class)->handle($actor, $campaign, $target->getKey());

        self::assertSame(0, DB::table('audit_events')->where('action', 'broadcast.campaign.test_sent')->count());
        self::assertSame(1, DB::table('audit_events')->where('action', 'broadcast.campaign.test_failed')->count());
    }

    public function test_queue_dispatch_failure_is_backed_off_and_does_not_hot_loop_scheduler(): void
    {
        [$organization, $actor] = $this->fixture();
        $this->client($organization, consent: true, verified: true, language: 'ru');
        $campaign = $this->campaign($actor, []);
        app(MaterializeBroadcastAudience::class)->handle($campaign);
        $campaign->forceFill(['state' => BroadcastCampaignState::Dispatching, 'scheduled_at' => now(), 'dispatch_started_at' => now()])->save();

        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $first = app(ScheduleBroadcastWork::class)->handle();
        $second = app(ScheduleBroadcastWork::class)->handle();

        self::assertSame(1, $first['campaigns']);
        self::assertSame(0, $first['batches']);
        self::assertSame(0, $second['campaigns']);
        self::assertSame('pending', DB::table('broadcast_batches')->value('state'));
        self::assertSame('queue_dispatch_failed', DB::table('broadcast_batches')->value('last_dispatch_error_code'));
        self::assertNotNull(BroadcastCampaign::query()->value('next_dispatch_at'));
    }

    /** @return array{Organization, User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $actor = User::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $actor];
    }

    /** @param list<array{key: string, operator: string, value: mixed}> $filters */
    private function campaign(User $actor, array $filters, string $mode = 'immediate', mixed $scheduledAt = null): BroadcastCampaign
    {
        $data = $this->campaignData($filters);
        $data['send_mode'] = $mode;
        $data['scheduled_at'] = $scheduledAt;

        return app(CreateBroadcastCampaign::class)->handle($actor, $data);
    }

    /**
     * @param  list<array{key: string, operator: string, value: mixed}>  $filters
     * @return array<string, mixed>
     */
    private function campaignData(array $filters): array
    {
        $organization = app(OrganizationContext::class)->organization();
        $template = NotificationTemplate::factory()->forOrganization($organization)->create(['purpose' => ScenarioRulePurpose::Marketing->value, 'locale' => 'ru']);
        $version = NotificationTemplateVersion::factory()->forTemplate($template)->create(['body' => 'Здравствуйте, {{ client.full_name }}!', 'variables' => ['client.full_name']]);

        return ['name' => 'Проверочная рассылка', 'send_mode' => 'immediate', 'channel_priority' => ['telegram'], 'segment_definition' => $filters, 'template_version_ru_id' => $version->getKey(), 'template_version_en_id' => null, 'scheduled_at' => null];
    }

    private function client(Organization $organization, bool $consent, bool $verified, string $language): Client
    {
        $client = Client::factory()->forOrganization($organization)->create(['language' => $language]);
        ClientConsent::factory()->forClient($client)->create(['subject' => ConsentSubject::Marketing->value, 'is_required' => false, 'granted' => $consent]);
        ClientChannelIdentity::factory()->forClient($client)->create(['channel' => 'telegram', 'external_id' => 'chat-'.$client->getKey(), 'verification_status' => $verified ? ChannelIdentityStatus::Verified->value : ChannelIdentityStatus::Unverified->value, 'verification_method' => $verified ? 'test' : null, 'verified_at' => $verified ? now() : null]);

        return $client;
    }

    private function completedBooking(Organization $organization, Client $client, mixed $startsAt): void
    {
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $serviceRecord = Service::factory()->forOrganization($organization)->create();
        $service = Service::query()->whereKey($serviceRecord->getKey())->firstOrFail();
        Booking::factory()->forOrganization($organization)->forClient($client)->forSpecialist($specialist)->forService($service)->create(['status' => BookingStatus::Completed->value, 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(), 'blocking_ends_at' => $startsAt->copy()->addHour()]);
    }

    private function surveyCompletion(Organization $organization, Client $client): void
    {
        $definitionId = DB::table('survey_definitions')->insertGetId(['organization_id' => $organization->getKey(), 'definition_key' => 'generic-'.$client->getKey(), 'title' => 'Generic', 'is_available' => true, 'created_at' => now(), 'updated_at' => now()]);
        $versionId = DB::table('survey_versions')->insertGetId(['organization_id' => $organization->getKey(), 'survey_definition_id' => $definitionId, 'version' => 1, 'status' => 'published', 'title' => 'Generic', 'definition' => '{}', 'scoring' => '{}', 'published_at' => now(), 'created_at' => now()]);
        DB::table('survey_attempts')->insert(['organization_id' => $organization->getKey(), 'client_id' => $client->getKey(), 'survey_definition_id' => $definitionId, 'survey_version_id' => $versionId, 'status' => 'completed', 'definition_snapshot' => 'x', 'scoring_snapshot' => 'x', 'started_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
