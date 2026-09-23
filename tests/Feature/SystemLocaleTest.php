<?php

namespace Tests\Feature;

use App\Http\Middleware\SetAdminLocale;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingType;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use App\Support\SupportedLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class SystemLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_locale_uses_the_current_organization_default_language(): void
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        OrganizationSetting::factory()->forOrganization($organization)->create([
            'setting_key' => OrganizationSettingKey::DefaultLanguage->value,
            'value_type' => OrganizationSettingType::String->value,
            'string_value' => 'en',
        ]);
        $request = Request::create('/admin', 'GET');

        app(SetAdminLocale::class)->handle($request, static function (): Response {
            return new Response('ok');
        });

        self::assertSame('en', app()->getLocale());
    }

    public function test_admin_locale_prefers_the_selected_session_language(): void
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        OrganizationSetting::factory()->forOrganization($organization)->create([
            'setting_key' => OrganizationSettingKey::DefaultLanguage->value,
            'value_type' => OrganizationSettingType::String->value,
            'string_value' => 'ru',
        ]);
        $request = Request::create('/admin', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(SupportedLocale::AdminSessionKey, 'en');

        app(SetAdminLocale::class)->handle($request, static function (): Response {
            return new Response('ok');
        });

        self::assertSame('en', app()->getLocale());
    }

    public function test_admin_locale_rejects_unsupported_session_and_organization_languages(): void
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        OrganizationSetting::factory()->forOrganization($organization)->create([
            'setting_key' => OrganizationSettingKey::DefaultLanguage->value,
            'value_type' => OrganizationSettingType::String->value,
            'string_value' => 'fr',
        ]);
        $request = Request::create('/admin', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(SupportedLocale::AdminSessionKey, 'de');

        app(SetAdminLocale::class)->handle($request, static function (): Response {
            return new Response('ok');
        });

        self::assertSame('ru', app()->getLocale());
    }

    public function test_invalid_session_locale_falls_back_to_valid_organization_language(): void
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        OrganizationSetting::factory()->forOrganization($organization)->create([
            'setting_key' => OrganizationSettingKey::DefaultLanguage->value,
            'value_type' => OrganizationSettingType::String->value,
            'string_value' => 'en',
        ]);
        $request = Request::create('/admin', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(SupportedLocale::AdminSessionKey, 'de');

        app(SetAdminLocale::class)->handle($request, static function (): Response {
            return new Response('ok');
        });

        self::assertSame('en', app()->getLocale());
    }
}
