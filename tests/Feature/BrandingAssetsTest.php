<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandingAssetsTest extends TestCase
{
    public function test_current_brand_assets_are_available(): void
    {
        $assets = [
            'brand/chuklov-designer-logo-en.jpg',
            'brand/chuklov-designer-logo-ru-school.jpg',
            'brand/chuklov-designer-logo-ru.jpg',
            'brand/chuklov-designer-logo-sochi.jpg',
        ];

        foreach ($assets as $path) {
            $absolutePath = public_path($path);

            self::assertFileExists($absolutePath);
            self::assertGreaterThan(0, filesize($absolutePath));
            self::assertNotFalse(getimagesize($absolutePath));
        }

        $brandAssets = glob(public_path('brand/*'));

        self::assertIsArray($brandAssets);
        self::assertSame(array_map('basename', $assets), array_map('basename', $brandAssets));
        self::assertFileDoesNotExist(public_path('favicon.ico'));
    }

    public function test_legacy_and_generated_brand_assets_are_absent(): void
    {
        foreach ([
            'brand/chuklov-app-icon-v2.png',
            'brand/chuklov-app-icon.png',
            'brand/chuklov-emblem.png',
            'brand/chuklov-favicon.png',
            'brand/chuklov-logo.jpg',
            'brand/chuklov-logo.svg',
            'brand/chuklov-mark.png',
            'brand/chuklov-mark.svg',
        ] as $path) {
            self::assertFileDoesNotExist(public_path($path));
        }
    }

    public function test_root_template_links_the_browser_icons(): void
    {
        $template = file_get_contents(resource_path('views/app.blade.php'));

        self::assertIsString($template);
        self::assertStringContainsString('rel="icon" type="image/jpeg" href="{{ asset(\'brand/chuklov-designer-logo-en.jpg\') }}"', $template);
        self::assertStringContainsString('rel="apple-touch-icon" href="{{ asset(\'brand/chuklov-designer-logo-en.jpg\') }}"', $template);
    }

    public function test_admin_panel_uses_the_chuklov_favicon(): void
    {
        $provider = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));

        self::assertIsString($provider);
        self::assertStringContainsString("->favicon(asset('brand/chuklov-designer-logo-en.jpg'))", $provider);
    }

    public function test_portal_shell_uses_the_designer_locale_logos(): void
    {
        $shell = file_get_contents(resource_path('js/Components/Portal/AppShell.vue'));

        self::assertIsString($shell);
        self::assertStringContainsString("'/brand/chuklov-designer-logo-en.jpg'", $shell);
        self::assertStringContainsString("'/brand/chuklov-designer-logo-ru.jpg'", $shell);
        self::assertStringNotContainsString('chuklov-logo.svg', $shell);
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
