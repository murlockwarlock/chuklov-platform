<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Channels\Infrastructure\Telegram\SendTelegramWelcome;
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
        $section = ContentSection::factory()->forOrganization($organization)->create(['section_key' => 'telegram_welcome', 'locale' => 'ru']);
        $file = UploadedFile::fake()->createWithContent('welcome.mp4', pack('N', 24).'ftypmp42'.pack('N', 0).'mp42isom');
        app(AttachContentVideo::class)->handle($actor, $section, $file);
        $section->refresh();
        Storage::disk('content-video-test')->assertExists($section->media['video']);
        $media = app(GetClientEntryMedia::class)->handle('ru');
        self::assertCount(5, $media);
        self::assertNotNull($media['telegram_welcome']['videoUrl']);
        self::assertSame(array_keys(GetClientEntryMedia::STORIES), array_keys(array_slice($media, 1)));
        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $bot->setCommonChat(Chat::make(id: 990026, type: 'private'));
        $bot->setCommonUser(\SergiX44\Nutgram\Telegram\Types\User\User::make(id: 990026, is_bot: false, first_name: 'Synthetic QA', language_code: 'ru'));
        config(['tenancy.default_organization_id' => $organization->id]);
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
            self::assertSame($media['telegram_welcome']['videoUrl'], json_decode($videoBody, true, flags: JSON_THROW_ON_ERROR)['video']);
        }
        self::assertCount(1, $message['reply_markup']['inline_keyboard']);
        $button = $message['reply_markup']['inline_keyboard'][0][0];
        self::assertSame('Открыть приложение', $button['text']);
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

    public function test_video_and_menu_provider_failures_preserve_the_mini_app_auth_continuation(): void
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
        $bot->expects($this->once())->method('sendMessage')->willReturnCallback(function (string $text, mixed ...$parameters) use ($continuation): ?Message {
            self::assertSame(config('portal.telegram.greeting.ru'), $text);
            self::assertInstanceOf(InlineKeyboardMarkup::class, $parameters[11]);
            $keyboard = json_decode(json_encode($parameters[11], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(1, $keyboard['inline_keyboard']);
            self::assertCount(1, $keyboard['inline_keyboard'][0]);
            self::assertSame('Открыть приложение', $keyboard['inline_keyboard'][0][0]['text']);
            self::assertSame($continuation, $keyboard['inline_keyboard'][0][0]['web_app']['url']);
            self::assertArrayNotHasKey('url', $keyboard['inline_keyboard'][0][0]);

            return null;
        });
        $bot->expects($this->once())->method('setChatMenuButton')->willThrowException(new ConnectException('Sensitive connection error', new Request('POST', 'https://api.telegram.org/bot-secret')));
        Log::shouldReceive('warning')->once()->with('telegram_welcome_video_unavailable');
        Log::shouldReceive('warning')->once()->with('telegram_welcome_menu_unavailable');

        app(SendTelegramWelcome::class)->handle($bot, 'ru', $continuation);
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

    public function test_greeting_delivery_failure_is_not_reported_as_a_success(): void
    {
        app(OrganizationContext::class)->set(Organization::factory()->create());
        config(['portal.telegram.portal_url' => 'https://mini.example.test']);
        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->never())->method('sendVideo');
        $bot->expects($this->once())->method('sendMessage')->willThrowException(new TelegramException('Provider unavailable', 503));
        $bot->expects($this->never())->method('setChatMenuButton');

        $this->expectException(TelegramException::class);
        app(SendTelegramWelcome::class)->handle($bot, 'ru');
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
