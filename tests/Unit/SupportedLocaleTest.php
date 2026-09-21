<?php

namespace Tests\Unit;

use App\Support\SupportedLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SupportedLocaleTest extends TestCase
{
    #[DataProvider('localeValues')]
    public function test_supported_locale_normalizes_known_values_and_falls_back_to_russian(
        ?string $value,
        string $expected,
    ): void {
        self::assertSame($expected, SupportedLocale::normalize($value));
    }

    public static function localeValues(): array
    {
        return [
            'russian' => ['ru', 'ru'],
            'russian region' => ['ru-RU', 'ru'],
            'english' => ['en', 'en'],
            'english region' => ['en-US', 'en'],
            'unsupported' => ['de', 'ru'],
            'missing' => [null, 'ru'],
        ];
    }
}
