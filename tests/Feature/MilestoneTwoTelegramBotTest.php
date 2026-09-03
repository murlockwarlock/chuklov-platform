<?php

namespace Tests\Feature;

use App\Modules\Channels\Application\GetTelegramMenu;
use App\Modules\Channels\Application\TelegramMessagePreview;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationActionButton;
use App\Modules\Channels\Domain\ValueObjects\NotificationMedia;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Channels\Infrastructure\Telegram\TelegramNotificationChannel;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaDocument;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaPhoto;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaVideo;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class MilestoneTwoTelegramBotTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_renders_the_localized_menu_through_fake_transport(): void
    {
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');

        $this->assertLocalizedMenu('ru', [
            'portal' => 'Открыть портал',
            'author' => 'Об авторе',
            'method' => 'Метод',
            'b2b' => '🚀 Хочешь себе такого бота? / Развить бизнес',
            'partner' => 'Партнёры',
        ]);
        $this->assertLocalizedMenu('en', [
            'portal' => 'Open client portal',
            'author' => 'Author',
            'method' => 'Method',
            'b2b' => '🚀 Want a bot like this? / Grow your business',
            'partner' => 'Partners',
        ]);
    }

    public function test_external_menu_definition_remains_an_ordinary_url_button(): void
    {
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        config()->set('portal.telegram.entries.external_demo', [
            'launch' => 'external_url',
            'url' => 'https://external.example.test/help?source=telegram#faq',
        ]);
        config()->set('portal.telegram.menu.en', [
            ...config('portal.telegram.menu.en'),
            ['key' => 'external_demo', 'label' => 'External help'],
        ]);

        $external = collect(app(GetTelegramMenu::class)->handle('en'))->firstWhere('key', 'external_demo');

        self::assertIsArray($external);
        self::assertFalse($external['web_app']);
        self::assertSame('external_url', $external['launch']);
        self::assertSame('https://external.example.test/help?source=telegram#faq', $external['url']);
    }

    public function test_external_menu_definitions_reject_unsafe_urls(): void
    {
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        $unsafeUrls = [
            'https://user@external.example.test/help',
            'https://user:pass@external.example.test/help',
            'javascript:alert(1)',
            'data:text/html,test',
            '//external.example.test/help',
            'https://?source=telegram',
            'not-a-url',
        ];
        $entries = config('portal.telegram.entries');
        $menu = config('portal.telegram.menu.en');

        foreach ($unsafeUrls as $index => $url) {
            $key = 'external_unsafe_'.$index;
            $entries[$key] = [
                'launch' => 'external_url',
                'url' => $url,
            ];
            $menu[] = ['key' => $key, 'label' => 'Unsafe external '.$index];
        }

        config()->set('portal.telegram.entries', $entries);
        config()->set('portal.telegram.menu.en', $menu);

        $resolvedMenu = app(GetTelegramMenu::class)->handle('en');

        foreach (array_keys($unsafeUrls) as $index) {
            self::assertNull(collect($resolvedMenu)->firstWhere('key', 'external_unsafe_'.$index));
        }
    }

    public function test_feedback_notification_uses_a_mini_app_button_without_an_external_url(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $channel = new TelegramNotificationChannel($bot);

        $result = $channel->send(new NotificationMessage(
            recipientExternalId: 'feedback-chat',
            body: 'Оцените визит',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'feedback-1',
            webAppUrl: 'https://mini.example.test/portal/telegram/launch/feedback',
        ));

        self::assertSame('delivered', $result->outcome->value);
        $bot->assertCalled('sendMessage');
        $history = array_values($bot->getRequestHistory());
        $request = array_values($history[0])[0];
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $button = $body['reply_markup']['inline_keyboard'][0][0];

        self::assertSame('Оценить визит', $button['text']);
        self::assertSame(
            'https://mini.example.test/portal/telegram/launch/feedback',
            $button['web_app']['url'],
        );
        self::assertArrayNotHasKey('url', $button);
    }

    public function test_meeting_notification_uses_an_inline_url_button_without_putting_the_url_in_the_body(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $channel = new TelegramNotificationChannel($bot);
        $url = 'https://zoom.us/j/123456?pwd=test-password';

        $result = $channel->send(new NotificationMessage(
            recipientExternalId: 'meeting-chat',
            body: 'Запись подтверждена на 02.09.2026 в 14:57.',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'meeting-1',
            actionButton: new NotificationActionButton('Подключиться к встрече', $url),
        ));

        self::assertSame('delivered', $result->outcome->value);
        $bot->assertCalled('sendMessage');
        $history = array_values($bot->getRequestHistory());
        $request = array_values($history[0])[0];
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $button = $body['reply_markup']['inline_keyboard'][0][0];

        self::assertSame('Запись подтверждена на 02.09.2026 в 14:57.', $body['text']);
        self::assertSame('Подключиться к встрече', $button['text']);
        self::assertSame($url, $button['url']);
        self::assertArrayNotHasKey('web_app', $button);
    }

    public function test_invalid_web_app_url_fails_closed_before_telegram_delivery(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $channel = new TelegramNotificationChannel($bot);

        $result = $channel->send(new NotificationMessage(
            recipientExternalId: 'feedback-chat',
            body: 'Оцените визит',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'feedback-invalid-url',
            webAppUrl: 'http://mini.example.test/feedback',
        ));

        self::assertSame('unavailable', $result->outcome->value);
        self::assertSame('invalid_web_app_url', $result->errorCode);
        self::assertCount(0, $bot->getRequestHistory());
    }

    public function test_photo_and_text_modes_send_in_the_configured_order(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $channel = new TelegramNotificationChannel($bot);

        $result = $channel->send(new NotificationMessage(
            recipientExternalId: 'mode-chat',
            body: '<p><strong>Текст</strong> 😀</p>',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'image-then-text',
            mediaUrl: 'https://cdn.example.test/image.jpg',
            mode: NotificationMessageMode::ImageThenText,
        ));

        self::assertSame('delivered', $result->outcome->value);
        $history = array_values($bot->getRequestHistory());
        self::assertCount(2, $history);
        $photoBody = $this->requestBody($bot, 0);
        $textBody = $this->requestBody($bot, 1);
        self::assertArrayHasKey('photo', $photoBody);
        self::assertArrayNotHasKey('text', $photoBody);
        self::assertSame('<b>Текст</b> 😀', $textBody['text']);

        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $result = (new TelegramNotificationChannel($bot))->send(new NotificationMessage(
            recipientExternalId: 'mode-chat',
            body: '<p>Сначала текст</p>',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'text-then-image',
            mediaUrl: 'https://cdn.example.test/image.jpg',
            mode: NotificationMessageMode::TextThenImage,
        ));

        self::assertSame('delivered', $result->outcome->value);
        self::assertArrayHasKey('text', $this->requestBody($bot, 0));
        self::assertArrayHasKey('photo', $this->requestBody($bot, 1));
    }

    public function test_media_streams_are_uploaded_to_telegram_as_multipart_files(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        $uploaded = null;
        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())
            ->method('sendPhoto')
            ->willReturnCallback(function (InputFile|string $photo) use (&$uploaded): ?Message {
                $uploaded = $photo;

                return null;
            });
        $stream = fopen(__FILE__, 'rb');
        self::assertIsResource($stream);

        $result = (new TelegramNotificationChannel($bot))->send(new NotificationMessage(
            recipientExternalId: 'managed-media-chat',
            body: '<p>Подпись</p>',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'managed-media',
            mediaStream: $stream,
            mode: NotificationMessageMode::ImageWithCaption,
        ));

        self::assertSame('delivered', $result->outcome->value);
        self::assertInstanceOf(InputFile::class, $uploaded);
        self::assertFalse(is_resource($stream));
    }

    public function test_video_media_is_sent_with_telegram_video_method(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())
            ->method('sendVideo')
            ->willReturnCallback(function (InputFile|string $video): ?Message {
                self::assertSame('https://cdn.example.test/video.mp4', $video);

                return null;
            });

        $result = (new TelegramNotificationChannel($bot))->send(new NotificationMessage(
            recipientExternalId: 'video-chat',
            body: '',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'video-1',
            mode: NotificationMessageMode::Image,
            mediaItems: [new NotificationMedia('video', url: 'https://cdn.example.test/video.mp4', fileName: 'video.mp4')],
        ));

        self::assertSame('delivered', $result->outcome->value);
    }

    public function test_photo_and_video_media_are_sent_as_one_telegram_album(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())
            ->method('sendMediaGroup')
            ->willReturnCallback(function (array $media): ?array {
                self::assertCount(2, $media);
                self::assertInstanceOf(InputMediaPhoto::class, $media[0]);
                self::assertInstanceOf(InputMediaVideo::class, $media[1]);

                return null;
            });

        $result = (new TelegramNotificationChannel($bot))->send(new NotificationMessage(
            recipientExternalId: 'album-chat',
            body: '',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'album-1',
            mode: NotificationMessageMode::Image,
            mediaItems: [
                new NotificationMedia('photo', url: 'https://cdn.example.test/one.jpg', fileName: 'one.jpg'),
                new NotificationMedia('video', url: 'https://cdn.example.test/two.mp4', fileName: 'two.mp4'),
            ],
        ));

        self::assertSame('delivered', $result->outcome->value);
    }

    public function test_documents_are_sent_as_one_telegram_document_album(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())
            ->method('sendMediaGroup')
            ->willReturnCallback(function (array $media): ?array {
                self::assertCount(2, $media);
                self::assertContainsOnlyInstancesOf(InputMediaDocument::class, $media);

                return null;
            });

        $result = (new TelegramNotificationChannel($bot))->send(new NotificationMessage(
            recipientExternalId: 'document-album-chat',
            body: '',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'document-album-1',
            mode: NotificationMessageMode::Image,
            mediaItems: [
                new NotificationMedia('document', url: 'https://cdn.example.test/one.pdf', fileName: 'one.pdf'),
                new NotificationMedia('document', url: 'https://cdn.example.test/two.pdf', fileName: 'two.pdf'),
            ],
        ));

        self::assertSame('delivered', $result->outcome->value);
    }

    public function test_document_without_a_browser_preview_still_has_a_telegram_preview_card(): void
    {
        $preview = app(TelegramMessagePreview::class)->handle(new NotificationMessage(
            recipientExternalId: 'preview-chat',
            body: '',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'document-preview',
            mode: NotificationMessageMode::Image,
            mediaItems: [new NotificationMedia('document', fileName: 'consent.pdf')],
        ));

        self::assertTrue($preview['hasImage']);
        self::assertSame('document', $preview['mediaItems'][0]['type']);
        self::assertNull($preview['mediaItems'][0]['url']);
        self::assertSame('consent.pdf', $preview['mediaItems'][0]['name']);
    }

    public function test_telegram_media_rejection_is_saved_as_a_safe_actionable_code(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        $bot = FakeNutgram::instance(responses: [new Response(
            400,
            [],
            json_encode([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: failed to get HTTP URL content',
            ], JSON_THROW_ON_ERROR),
        )]);

        $result = (new TelegramNotificationChannel($bot))->send(new NotificationMessage(
            recipientExternalId: 'media-error-chat',
            body: '<p>Подпись</p>',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'media-error',
            mediaUrl: 'https://cdn.example.test/image.jpg',
            mode: NotificationMessageMode::ImageWithCaption,
            requireKnownExternalOutcome: true,
        ));

        self::assertSame('permanent_failure', $result->outcome->value);
        self::assertSame('telegram_media_unavailable', $result->errorCode);
    }

    public function test_caption_position_and_telegram_boundaries_are_enforced(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $channel = new TelegramNotificationChannel($bot);

        $above = $channel->send(new NotificationMessage(
            recipientExternalId: 'caption-chat',
            body: '<p><strong>Подпись</strong></p>',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'caption-above',
            mediaUrl: 'https://cdn.example.test/image.jpg',
            mode: NotificationMessageMode::ImageWithCaption,
            showCaptionAboveMedia: true,
        ));

        self::assertSame('delivered', $above->outcome->value);
        $aboveBody = $this->requestBody($bot, 0);
        self::assertSame('<b>Подпись</b>', $aboveBody['caption']);
        self::assertTrue($aboveBody['show_caption_above_media']);

        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $below = (new TelegramNotificationChannel($bot))->send(new NotificationMessage(
            recipientExternalId: 'caption-chat',
            body: '<p>Подпись</p>',
            subject: null,
            locale: 'ru',
            idempotencyKey: 'caption-below',
            mediaUrl: 'https://cdn.example.test/image.jpg',
            mode: NotificationMessageMode::ImageWithCaption,
        ));

        self::assertSame('delivered', $below->outcome->value);
        self::assertFalse($this->requestBody($bot, 0)['show_caption_above_media']);

        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $channel = new TelegramNotificationChannel($bot);
        $acceptedText = $channel->send(new NotificationMessage(
            recipientExternalId: 'limit-chat',
            body: str_repeat('a', 4096),
            subject: null,
            locale: 'en',
            idempotencyKey: 'text-limit-ok',
        ));
        $rejectedText = $channel->send(new NotificationMessage(
            recipientExternalId: 'limit-chat',
            body: str_repeat('a', 4097),
            subject: null,
            locale: 'en',
            idempotencyKey: 'text-limit-fail',
        ));
        $acceptedCaption = $channel->send(new NotificationMessage(
            recipientExternalId: 'limit-chat',
            body: str_repeat('a', 1024),
            subject: null,
            locale: 'en',
            idempotencyKey: 'caption-limit-ok',
            mediaUrl: 'https://cdn.example.test/image.jpg',
            mode: NotificationMessageMode::ImageWithCaption,
        ));
        $rejectedCaption = $channel->send(new NotificationMessage(
            recipientExternalId: 'limit-chat',
            body: str_repeat('a', 1025),
            subject: null,
            locale: 'en',
            idempotencyKey: 'caption-limit-fail',
            mediaUrl: 'https://cdn.example.test/image.jpg',
            mode: NotificationMessageMode::ImageWithCaption,
        ));

        self::assertSame('delivered', $acceptedText->outcome->value);
        self::assertSame('telegram_message_too_long', $rejectedText->errorCode);
        self::assertSame('delivered', $acceptedCaption->outcome->value);
        self::assertSame('telegram_message_too_long', $rejectedCaption->errorCode);
    }

    public function test_canonical_mini_app_origin_accepts_https_host_with_optional_trailing_slash(): void
    {
        foreach (['https://mini.example.test', 'https://mini.example.test/'] as $configuredUrl) {
            config()->set('portal.telegram.portal_url', $configuredUrl);

            self::assertCount(5, app(GetTelegramMenu::class)->handle('en'));
        }
    }

    public function test_mini_app_entries_are_omitted_when_the_canonical_url_is_invalid(): void
    {
        foreach ([
            'http://mini.example.test',
            'https://user@mini.example.test',
            'https://user:pass@mini.example.test',
            'https://mini.example.test/path',
            'https://mini.example.test/?source=telegram',
            'https://mini.example.test/#fragment',
            'https://mini.example.test?',
            'https://mini.example.test#',
        ] as $configuredUrl) {
            config()->set('portal.telegram.portal_url', $configuredUrl);

            self::assertSame([], app(GetTelegramMenu::class)->handle('ru'));
        }
    }

    /** @param array<string, string> $expected */
    private function assertLocalizedMenu(string $language, array $expected): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $bot->setCommonUser(User::make(
            id: $language === 'ru' ? 555001 : 555002,
            is_bot: false,
            first_name: ucfirst($language),
            language_code: $language,
        ));

        $bot->hearText('/start')->reply();

        $bot->assertCalled('sendMessage');
        $history = array_values($bot->getRequestHistory());
        $request = array_values($history[0])[0];
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $keyboard = $body['reply_markup']['inline_keyboard'];

        self::assertCount(count($expected), $keyboard);

        foreach (array_keys($expected) as $index => $key) {
            $button = $keyboard[$index][0];

            self::assertSame($expected[$key], $button['text']);
            self::assertArrayHasKey('web_app', $button);
            self::assertArrayNotHasKey('url', $button);
            self::assertSame(
                'https://mini.example.test/portal/telegram/launch/'.$key,
                $button['web_app']['url'],
            );
        }
    }

    /** @return array<string, mixed> */
    private function requestBody(FakeNutgram $bot, int $index): array
    {
        $history = array_values($bot->getRequestHistory());
        $request = array_values($history[$index])[0];
        $body = FakeNutgram::getActualData($request, ['show_caption_above_media' => true]);

        self::assertIsArray($body);

        return $body;
    }
}
