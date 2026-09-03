<?php

namespace Tests\Unit;

use App\Modules\Attribution\Application\AttributionSourcePresentation;
use PHPUnit\Framework\TestCase;

final class AttributionSourcePresentationTest extends TestCase
{
    public function test_known_source_codes_have_human_labels(): void
    {
        $presentation = new AttributionSourcePresentation;

        self::assertSame('По рекомендации знакомых', $presentation->label('friend'));
        self::assertSame('Социальные сети', $presentation->label('social'));
        self::assertSame('Поиск в интернете', $presentation->label('search'));
        self::assertSame('Партнёр', $presentation->label('partner'));
        self::assertSame('Другое', $presentation->label('other'));
    }

    public function test_technical_and_custom_sources_are_presented_without_leaking_empty_values(): void
    {
        $presentation = new AttributionSourcePresentation;

        self::assertSame('Telegram', $presentation->label('telegram'));
        self::assertSame('Реферальный переход', $presentation->label(null, 'referral'));
        self::assertSame('UTM-метки', $presentation->label(null, 'utm'));
        self::assertSame('Не указан', $presentation->label(null, 'source'));
        self::assertSame('Вебинар партнёра', $presentation->label('Вебинар партнёра'));
    }
}
