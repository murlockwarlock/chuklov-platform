<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class TelegramFoundationTest extends TestCase
{
    public function test_start_handler_loads_with_a_fake_token_and_no_network_call(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);

        $bot = app(Nutgram::class);

        self::assertInstanceOf(FakeNutgram::class, $bot);
        self::assertSame(0, Artisan::call('nutgram:list'));
        self::assertStringContainsString('start', Artisan::output());
        $bot->assertNoReply();
    }
}
