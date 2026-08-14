<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandingAssetsTest extends TestCase
{
    public function test_current_brand_assets_are_available(): void
    {
        $assets = [
            'brand/chuklov-logo.svg',
            'brand/chuklov-mark.svg',
            'brand/chuklov-app-icon-v2.png',
        ];

        foreach ($assets as $path) {
            $absolutePath = public_path($path);

            self::assertFileExists($absolutePath);
            self::assertGreaterThan(0, filesize($absolutePath));
            if (str_ends_with($path, '.svg')) {
                self::assertStringContainsString('<svg', (string) file_get_contents($absolutePath));
            } else {
                self::assertNotFalse(getimagesize($absolutePath));
            }
        }
    }

    public function test_root_template_links_the_browser_icons(): void
    {
        $template = file_get_contents(resource_path('views/app.blade.php'));

        self::assertIsString($template);
        self::assertStringContainsString('rel="icon" type="image/svg+xml" href="{{ asset(\'brand/chuklov-mark.svg\') }}"', $template);
        self::assertStringContainsString('rel="icon" type="image/png" sizes="512x512" href="{{ asset(\'brand/chuklov-app-icon-v2.png\') }}"', $template);
        self::assertStringContainsString('rel="apple-touch-icon" sizes="512x512" href="{{ asset(\'brand/chuklov-app-icon-v2.png\') }}"', $template);
    }

    public function test_admin_panel_uses_the_chuklov_favicon(): void
    {
        $provider = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));

        self::assertIsString($provider);
        self::assertStringContainsString("->favicon(asset('brand/chuklov-mark.svg'))", $provider);
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
