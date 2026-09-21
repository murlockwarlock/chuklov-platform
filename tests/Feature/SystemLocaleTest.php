<?php

namespace Tests\Feature;

use App\Http\Middleware\SetAdminLocale;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingType;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
