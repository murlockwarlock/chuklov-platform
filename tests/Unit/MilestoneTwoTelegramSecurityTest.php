<?php

namespace Tests\Unit;

use App\Modules\Channels\Infrastructure\Telegram\InvalidTelegramInitData;
use App\Modules\Channels\Infrastructure\Telegram\TelegramInitDataVerifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\Support\TelegramInitData;
use Tests\TestCase;

class MilestoneTwoTelegramSecurityTest extends TestCase
{
    public function test_valid_init_data_is_verified_without_using_frontend_identity_fields(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        Cache::flush();

        $verified = app(TelegramInitDataVerifier::class)->handle(
            TelegramInitData::make(777001, now()->timestamp, firstName: 'Signed'),
        );

        self::assertSame('telegram', $verified->channel);
        self::assertSame('777001', $verified->externalId);
        self::assertSame('Signed Client', $verified->displayName);
    }

    public function test_invalid_signature_and_replay_are_rejected(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        Cache::flush();
        $payload = TelegramInitData::make(777002, now()->timestamp);
        $verifier = app(TelegramInitDataVerifier::class);

        $verifier->handle($payload);

        $this->expectException(InvalidTelegramInitData::class);
        $verifier->handle($payload);
    }

    public function test_stale_init_data_is_rejected(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        Cache::flush();
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));

        try {
            $this->expectException(InvalidTelegramInitData::class);
            app(TelegramInitDataVerifier::class)->handle(
                TelegramInitData::make(777003, 1_800_000_000 - 901),
            );
        } finally {
            Carbon::setTestNow();
        }
    }
}
