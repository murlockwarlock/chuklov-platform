<?php

namespace Tests\Feature;

use App\Modules\Channels\Application\GetTelegramMenu;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Channels\Infrastructure\Telegram\TelegramNotificationChannel;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class MilestoneTwoTelegramBotTest extends TestCase
{
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
}
