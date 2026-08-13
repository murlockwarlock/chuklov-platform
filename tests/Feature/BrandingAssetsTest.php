<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandingAssetsTest extends TestCase
{
    public function test_original_brand_assets_are_preserved(): void
    {
        $assets = [
            'brand/chuklov-emblem.png' => '724a54661c864b45531dc7855c7b354e82f49a69204ffff6b22eff6328a0e01b',
            'brand/chuklov-mark.png' => 'a51aec77a667c92091ded3ac13d96cf4351a9a254d92ac14b411c80d6c88f973',
            'brand/chuklov-app-icon.png' => 'efb403d1d2b1086e13abb928ed2a4ec20ac93a0bc95c682a3d00a59afc96a599',
        ];

        foreach ($assets as $path => $expectedHash) {
            $absolutePath = public_path($path);

            self::assertFileExists($absolutePath);
            self::assertSame($expectedHash, hash_file('sha256', $absolutePath));
        }
    }

    public function test_root_template_links_the_browser_icons(): void
    {
        $template = file_get_contents(resource_path('views/app.blade.php'));

        self::assertIsString($template);
        self::assertStringContainsString('rel="icon" type="image/png" href="{{ asset(\'brand/chuklov-mark.png\') }}"', $template);
        self::assertStringContainsString('rel="apple-touch-icon" href="{{ asset(\'brand/chuklov-app-icon.png\') }}"', $template);
    }

    public function test_root_template_loads_telegram_web_app_before_the_application_bundle(): void
    {
        $template = file_get_contents(resource_path('views/app.blade.php'));

        self::assertIsString($template);
        self::assertStringContainsString('src="https://telegram.org/js/telegram-web-app.js"', $template);
        self::assertLessThan(
            strpos($template, "@vite(['resources/css/app.css', 'resources/js/app.ts'])"),
            strpos($template, 'src="https://telegram.org/js/telegram-web-app.js"'),
        );
    }
}
