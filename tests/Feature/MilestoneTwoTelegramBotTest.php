<?php

namespace Tests\Feature;

use App\Modules\Channels\Application\GetTelegramMenu;
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
            'url' => 'https://external.example.test/help?source=telegram',
        ]);
        config()->set('portal.telegram.menu.en', [
            ...config('portal.telegram.menu.en'),
            ['key' => 'external_demo', 'label' => 'External help'],
        ]);

        $external = collect(app(GetTelegramMenu::class)->handle('en'))->firstWhere('key', 'external_demo');

        self::assertIsArray($external);
        self::assertFalse($external['web_app']);
        self::assertSame('external_url', $external['launch']);
        self::assertSame('https://external.example.test/help?source=telegram', $external['url']);
    }

    public function test_mini_app_entries_are_omitted_when_the_canonical_url_is_invalid(): void
    {
        config()->set('portal.telegram.portal_url', 'http://mini.example.test');

        self::assertSame([], app(GetTelegramMenu::class)->handle('ru'));
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
