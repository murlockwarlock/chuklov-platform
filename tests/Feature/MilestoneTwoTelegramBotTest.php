<?php

namespace Tests\Feature;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class MilestoneTwoTelegramBotTest extends TestCase
{
    public function test_start_renders_the_localized_menu_through_fake_transport(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $bot->setCommonUser(User::make(
            id: 555001,
            is_bot: false,
            first_name: 'Russian',
            language_code: 'ru',
        ));

        $bot->hearText('/start')->reply();

        $bot->assertCalled('sendMessage');
        $history = array_values($bot->getRequestHistory());
        $request = array_values($history[0])[0];
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $keyboard = $body['reply_markup']['inline_keyboard'];

        self::assertCount(5, $keyboard);
        self::assertSame('Открыть портал', $keyboard[0][0]['text']);
        self::assertArrayHasKey('web_app', $keyboard[0][0]);
        self::assertSame('Об авторе', $keyboard[1][0]['text']);
    }
}
