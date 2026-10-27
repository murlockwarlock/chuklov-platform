<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Channels\Infrastructure\Telegram\SendTelegramWelcome;
use App\Modules\Content\Application\AttachContentSubtitles;
use App\Modules\Content\Application\AttachContentVideo;
use App\Modules\Content\Application\GetClientEntryMedia;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

final class September26ContentVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_video_is_persisted_and_welcome_uses_video_and_one_web_app_button(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['content_media.disk' => 'content-video-test', 'portal.telegram.portal_url' => 'https://mini.example.test', 'nutgram.token' => FakeNutgram::TOKEN]);
        Storage::fake('content-video-test');
        $actor = User::factory()->forOrganization($organization)->create();
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'telegram_welcome',
            'locale' => 'ru',
            'media' => ['caption' => 'Синтетическое видео-приветствие'],
        ]);
        $file = UploadedFile::fake()->createWithContent('welcome.mp4', pack('N', 24).'ftypmp42'.pack('N', 0).'mp42isom');
        app(AttachContentVideo::class)->handle($actor, $section, $file);
        $section->refresh();
        Storage::disk('content-video-test')->assertExists($section->media['video']);
        $media = app(GetClientEntryMedia::class)->handle('ru');
        self::assertCount(5, $media);
        self::assertNotNull($media['telegram_welcome']['videoUrl']);
        self::assertSame('Синтетическое видео-приветствие', $media['telegram_welcome']['caption']);
        self::assertSame(array_keys(GetClientEntryMedia::STORIES), array_keys(array_slice($media, 1)));
        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $bot->setCommonChat(Chat::make(id: 990026, type: 'private'));
        $bot->setCommonUser(\SergiX44\Nutgram\Telegram\Types\User\User::make(id: 990026, is_bot: false, first_name: 'Synthetic QA', language_code: 'ru'));
        config(['tenancy.default_organization_id' => $organization->id]);
        $bot->willReceive(['message_id' => 1, 'date' => 1, 'chat' => ['id' => 990026, 'type' => 'private']]);
        $bot->willReceive(['message_id' => 2, 'date' => 1, 'chat' => ['id' => 990026, 'type' => 'private']]);
        $bot->willReceive(true);
        $bot->hearText('/start')->reply();
        $bot->assertCalled('sendVideo');
        $bot->assertCalled('sendMessage');
        $bot->assertCalled('setChatMenuButton');
        $history = array_values($bot->getRequestHistory());
        $videoRequest = array_values($history[0])[0];
        $videoBody = (string) $videoRequest->getBody();
        $message = json_decode((string) array_values($history[1])[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(990026, $message['chat_id']);
        if (str_contains($videoRequest->getHeaderLine('Content-Type'), 'multipart/form-data')) {
            self::assertSame(1, preg_match('/name="video"\r\n(?:[^\r\n]+\r\n)*\r\n([^\r\n]+)/', $videoBody, $parts));
            self::assertSame($media['telegram_welcome']['videoUrl'], $parts[1]);
        } else {
            $videoPayload = json_decode($videoBody, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($media['telegram_welcome']['videoUrl'], $videoPayload['video']);
            self::assertSame('Синтетическое видео-приветствие', $videoPayload['caption']);
        }
        self::assertCount(1, $message['reply_markup']['inline_keyboard']);
        $button = $message['reply_markup']['inline_keyboard'][0][0];
        self::assertSame('🚀 Открыть приложение', $button['text']);
        self::assertSame('https://mini.example.test/portal/telegram/launch/portal', $button['web_app']['url']);
        self::assertArrayNotHasKey('url', $button);
    }

    public function test_non_video_upload_does_not_replace_the_current_media(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        $actor = User::factory()->forOrganization($organization)->create();
        $section = ContentSection::factory()->forOrganization($organization)->create();
        $this->expectException(ValidationException::class);
        app(AttachContentVideo::class)->handle($actor, $section, UploadedFile::fake()->createWithContent('fake.mp4', 'not video'));
    }

    public function test_missing_welcome_video_shows_recovery_copy_and_logs_safe_diagnostics(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['portal.telegram.portal_url' => 'https://mini.example.test']);
        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->never())->method('sendVideo');
        $bot->expects($this->once())->method('updateId')->willReturn(915205);
        $bot->expects($this->once())->method('sendMessage')->willReturnCallback(function (string $text, mixed ...$parameters): ?Message {
            self::assertSame(
                config('portal.telegram.greeting.ru')."\n\nВидео приветствия пока недоступно. Откройте приложение кнопкой ниже.",
                $text,
            );
            self::assertInstanceOf(InlineKeyboardMarkup::class, $parameters[11]);
            $keyboard = json_decode(json_encode($parameters[11], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(1, $keyboard['inline_keyboard']);
            self::assertSame('🚀 Открыть приложение', $keyboard['inline_keyboard'][0][0]['text']);

            return new Message;
        });
        $bot->expects($this->once())->method('setChatMenuButton')->willReturn(true);
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) use ($organization): bool {
            self::assertSame('telegram_welcome_degraded', $message);
            self::assertSame('telegram.welcome.video', $context['event']);
            self::assertSame($organization->id, $context['organization_id']);
            self::assertSame('telegram:update:915205', $context['correlation_id']);
            self::assertSame('telegram', $context['provider']);
            self::assertSame('welcome_video_unavailable', $context['error_code']);
            self::assertSame('unavailable', $context['outcome']);
            self::assertSame(0, $context['attempt']);
            self::assertNull($context['exception_class']);
            self::assertSame(0, (new \DateTimeImmutable($context['occurred_at']))->getOffset());

            return true;
        });

        app(SendTelegramWelcome::class)->handle($bot, 'ru');
    }

    public function test_video_and_menu_provider_failures_show_recovery_copy_and_log_safe_diagnostics(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['content_media.disk' => 'content-video-test', 'portal.telegram.portal_url' => 'https://mini.example.test']);
        Storage::fake('content-video-test');
        $actor = User::factory()->forOrganization($organization)->create();
        $section = ContentSection::factory()->forOrganization($organization)->create(['section_key' => 'telegram_welcome', 'locale' => 'ru']);
        app(AttachContentVideo::class)->handle($actor, $section, UploadedFile::fake()->createWithContent('welcome.mp4', pack('N', 24).'ftypmp42'.pack('N', 0).'mp42isom'));
        $continuation = 'https://mini.example.test/portal/telegram/auth/synthetic-continuation';
        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())->method('sendVideo')->willThrowException(new TelegramException('Sensitive provider error must not be logged', 400));
        $bot->expects($this->exactly(2))->method('updateId')->willReturn(915204);
        $sentMessages = [];
        $bot->expects($this->exactly(2))->method('sendMessage')->willReturnCallback(function (string $text, mixed ...$parameters) use ($continuation, &$sentMessages): ?Message {
            $sentMessages[] = [$text, $parameters];

            if (count($sentMessages) === 1) {
                self::assertSame(
                    config('portal.telegram.greeting.ru')."\n\nНе удалось загрузить видео приветствия. Откройте приложение кнопкой ниже.",
                    $text,
                );
                self::assertInstanceOf(InlineKeyboardMarkup::class, $parameters[11]);
                $keyboard = json_decode(json_encode($parameters[11], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                self::assertCount(1, $keyboard['inline_keyboard']);
                self::assertCount(1, $keyboard['inline_keyboard'][0]);
                self::assertSame('🚀 Открыть приложение', $keyboard['inline_keyboard'][0][0]['text']);
                self::assertSame($continuation, $keyboard['inline_keyboard'][0][0]['web_app']['url']);
                self::assertArrayNotHasKey('url', $keyboard['inline_keyboard'][0][0]);
            } else {
                self::assertSame('Кнопка меню сейчас недоступна. Откройте приложение кнопкой в приветствии выше.', $text);
            }

            return new Message;
        });
        $bot->expects($this->once())->method('setChatMenuButton')->willThrowException(new ConnectException('Sensitive connection error', new Request('POST', 'https://api.telegram.org/bot-secret')));
        $loggedEvents = [];
        Log::shouldReceive('warning')->twice()->withArgs(function (string $message, array $context = []) use (&$loggedEvents): bool {
            $loggedEvents[] = [$message, $context];

            return $message === 'telegram_welcome_degraded';
        });

        app(SendTelegramWelcome::class)->handle($bot, 'ru', $continuation);

        self::assertCount(2, $loggedEvents);
        foreach ($loggedEvents as [$message, $context]) {
            self::assertSame('telegram_welcome_degraded', $message);
            self::assertSame($organization->id, $context['organization_id']);
            self::assertSame('telegram', $context['provider']);
            self::assertSame('telegram_user', $context['actor_type']);
            self::assertSame('telegram:update:915204', $context['correlation_id']);
            self::assertSame(1, $context['attempt']);
            self::assertSame('failed', $context['outcome']);
            self::assertTrue(in_array($context['event'], ['telegram.welcome.video_send', 'telegram.welcome.menu_button'], true));
            self::assertTrue(in_array($context['error_code'], ['telegram_api_failure', 'telegram_transport_failure'], true));
            self::assertNotEmpty($context['exception_class']);
            self::assertSame(0, (new \DateTimeImmutable($context['occurred_at']))->getOffset());
        }

        $eventsByName = collect($loggedEvents)->mapWithKeys(fn (array $entry): array => [$entry[1]['event'] => $entry[1]]);
        self::assertSame('telegram_api_failure', $eventsByName['telegram.welcome.video_send']['error_code']);
        self::assertSame(400, $eventsByName['telegram.welcome.video_send']['provider_status']);
        self::assertSame('telegram_transport_failure', $eventsByName['telegram.welcome.menu_button']['error_code']);
        self::assertNull($eventsByName['telegram.welcome.menu_button']['provider_status']);

        self::assertStringNotContainsString('Sensitive provider error', json_encode($loggedEvents, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('bot-secret', json_encode($loggedEvents, JSON_THROW_ON_ERROR));
    }

    public function test_unconfirmed_menu_button_update_shows_recovery_copy_and_logs_unknown_outcome(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['content_media.disk' => 'content-video-test', 'portal.telegram.portal_url' => 'https://mini.example.test']);
        Storage::fake('content-video-test');
        $actor = User::factory()->forOrganization($organization)->create();
        $section = ContentSection::factory()->forOrganization($organization)->create(['section_key' => 'telegram_welcome', 'locale' => 'ru']);
        app(AttachContentVideo::class)->handle($actor, $section, UploadedFile::fake()->createWithContent('welcome.mp4', pack('N', 24).'ftypmp42'.pack('N', 0).'mp42isom'));

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())->method('sendVideo')->willReturn(new Message);
        $bot->expects($this->once())->method('updateId')->willReturn(915208);
        $sentMessages = [];
        $bot->expects($this->exactly(2))->method('sendMessage')->willReturnCallback(function (string $text, mixed ...$parameters) use (&$sentMessages): ?Message {
            $sentMessages[] = [$text, $parameters];

            if (count($sentMessages) === 1) {
                self::assertSame(config('portal.telegram.greeting.ru'), $text);
                self::assertInstanceOf(InlineKeyboardMarkup::class, $parameters[11]);
            } else {
                self::assertSame('Кнопка меню сейчас недоступна. Откройте приложение кнопкой в приветствии выше.', $text);
            }

            return new Message;
        });
        $bot->expects($this->once())->method('setChatMenuButton')->willReturn(null);
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) use ($organization): bool {
            self::assertSame('telegram_welcome_degraded', $message);
            self::assertSame('telegram.welcome.menu_button', $context['event']);
            self::assertSame($organization->id, $context['organization_id']);
            self::assertSame('telegram:update:915208', $context['correlation_id']);
            self::assertSame('menu_button_unconfirmed', $context['error_code']);
            self::assertSame('unknown', $context['outcome']);
            self::assertSame(1, $context['attempt']);
            self::assertNull($context['exception_class']);
            self::assertSame(0, (new \DateTimeImmutable($context['occurred_at']))->getOffset());

            return true;
        });

        app(SendTelegramWelcome::class)->handle($bot, 'ru');
    }

    public function test_foreign_organization_video_cannot_be_replaced_or_exposed(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        $actor = User::factory()->forOrganization($organization)->create();
        $foreign = ContentSection::factory()->forOrganization(Organization::factory()->create())->create(['section_key' => 'story_author']);
        self::assertNull(app(GetClientEntryMedia::class)->handle('ru')['story_author']['videoUrl']);
        $this->expectException(AuthorizationException::class);
        app(AttachContentVideo::class)->handle($actor, $foreign, UploadedFile::fake()->createWithContent('fake.mp4', 'not video'));
    }

    public function test_unconfirmed_greeting_delivery_is_not_reported_as_success(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['portal.telegram.portal_url' => 'https://mini.example.test']);
        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->never())->method('sendVideo');
        $bot->expects($this->exactly(2))->method('updateId')->willReturn(915207);
        $bot->expects($this->once())->method('sendMessage')->willReturn(null);
        $bot->expects($this->never())->method('setChatMenuButton');
        Log::shouldReceive('warning')->twice()->withArgs(function (string $message, array $context): bool {
            self::assertSame('telegram_welcome_degraded', $message);
            self::assertSame('telegram:update:915207', $context['correlation_id']);

            if ($context['event'] === 'telegram.welcome.video') {
                self::assertSame('welcome_video_unavailable', $context['error_code']);
                self::assertSame('unavailable', $context['outcome']);
            } else {
                self::assertSame('telegram.welcome.greeting_send', $context['event']);
                self::assertSame('greeting_delivery_unconfirmed', $context['error_code']);
                self::assertSame('unknown', $context['outcome']);
            }

            self::assertSame(0, (new \DateTimeImmutable($context['occurred_at']))->getOffset());

            return true;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Telegram welcome delivery was not confirmed.');
        app(SendTelegramWelcome::class)->handle($bot, 'ru');
    }

    public function test_greeting_delivery_failure_is_not_reported_as_a_success(): void
    {
        app(OrganizationContext::class)->set(Organization::factory()->create());
        config(['portal.telegram.portal_url' => 'https://mini.example.test']);
        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->never())->method('sendVideo');
        $bot->expects($this->exactly(2))->method('updateId')->willReturn(915206);
        $bot->expects($this->once())->method('sendMessage')->willThrowException(new TelegramException('Provider unavailable', 503));
        $bot->expects($this->never())->method('setChatMenuButton');
        Log::shouldReceive('warning')->twice()->withArgs(function (string $message, array $context): bool {
            self::assertSame('telegram_welcome_degraded', $message);
            self::assertSame('telegram:update:915206', $context['correlation_id']);
            self::assertSame('telegram', $context['provider']);
            self::assertSame(0, (new \DateTimeImmutable($context['occurred_at']))->getOffset());
            self::assertStringNotContainsString('Provider unavailable', json_encode($context, JSON_THROW_ON_ERROR));

            if ($context['event'] === 'telegram.welcome.video') {
                self::assertSame('welcome_video_unavailable', $context['error_code']);
                self::assertSame('unavailable', $context['outcome']);
                self::assertSame(0, $context['attempt']);
            } else {
                self::assertSame('telegram.welcome.greeting_send', $context['event']);
                self::assertSame('telegram_api_failure', $context['error_code']);
                self::assertSame(503, $context['provider_status']);
                self::assertSame('failed', $context['outcome']);
                self::assertSame(1, $context['attempt']);
            }

            return true;
        });

        $this->expectException(TelegramException::class);
        app(SendTelegramWelcome::class)->handle($bot, 'ru');
    }

    public function test_story_subtitles_can_be_uploaded_and_invalid_files_do_not_replace_them(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['content_media.disk' => 'content-video-test']);
        Storage::fake('content-video-test');
        $actor = User::factory()->forOrganization($organization)->create();
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'story_author', 'locale' => 'ru', 'delivery_mode' => 'mini_app', 'is_visible' => true,
        ]);
        app(AttachContentSubtitles::class)->handle($actor, $section, UploadedFile::fake()->createWithContent('story.vtt', "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nПривет\n"));
        $path = $section->refresh()->media['subtitles'];
        Storage::disk('content-video-test')->assertExists($path);
        self::assertSame(Storage::disk('content-video-test')->url($path), app(GetClientEntryMedia::class)->handle('ru')['story_author']['subtitlesUrl']);

        try {
            app(AttachContentSubtitles::class)->handle($actor, $section, UploadedFile::fake()->createWithContent('bad.vtt', 'invalid'));
            self::fail('Invalid subtitles must be rejected.');
        } catch (ValidationException) {
            self::assertSame($path, $section->refresh()->media['subtitles']);
        }
    }

    public function test_story_order_and_titles_follow_the_published_locale_configuration(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        $first = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'story_author', 'locale' => 'ru', 'delivery_mode' => 'mini_app',
            'title' => 'Первое название', 'sort_order' => 20, 'is_visible' => true,
        ]);
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'story_method', 'locale' => 'ru', 'delivery_mode' => 'mini_app',
            'title' => 'Второе название', 'sort_order' => 10, 'is_visible' => true,
        ]);

        $stories = array_slice(app(GetClientEntryMedia::class)->handle('ru'), 1, preserve_keys: true);
        self::assertSame(['story_method', 'story_author'], array_slice(array_keys($stories), 0, 2));
        self::assertSame('Первое название', $stories['story_author']['title']);

        $first->update(['sort_order' => 1, 'title' => 'Новое название']);
        $stories = array_slice(app(GetClientEntryMedia::class)->handle('ru'), 1, preserve_keys: true);
        self::assertSame('story_author', array_key_first($stories));
        self::assertSame('Новое название', $stories['story_author']['title']);
    }

    public function test_entry_media_respects_publication_and_the_selected_delivery_channel(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['content_media.disk' => 'content-video-test']);
        Storage::fake('content-video-test');
        $actor = User::factory()->forOrganization($organization)->create();
        $section = ContentSection::factory()->forOrganization($organization)->create(['section_key' => 'story_author', 'locale' => 'ru', 'delivery_mode' => 'telegram']);
        app(AttachContentVideo::class)->handle($actor, $section, UploadedFile::fake()->createWithContent('story.mp4', pack('N', 24).'ftypmp42'.pack('N', 0).'mp42isom'));
        self::assertNull(app(GetClientEntryMedia::class)->handle('ru')['story_author']['videoUrl']);
        $section->update(['delivery_mode' => 'mini_app']);
        self::assertNotNull(app(GetClientEntryMedia::class)->handle('ru')['story_author']['videoUrl']);
        $section->update(['is_visible' => false]);
        self::assertNull(app(GetClientEntryMedia::class)->handle('ru')['story_author']['videoUrl']);
    }
}
