<?php

namespace App\Http\Middleware;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use App\Support\SupportedLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetAdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = config('tenancy.default_organization_id');
        $setting = is_int($organizationId) || (is_string($organizationId) && ctype_digit($organizationId))
            ? OrganizationSetting::query()
                ->where('organization_id', (int) $organizationId)
                ->where('setting_key', OrganizationSettingKey::DefaultLanguage->value)
                ->value('string_value')
            : null;

        $sessionLocale = $request->hasSession()
            ? $request->session()->get(SupportedLocale::AdminSessionKey)
            : null;

        $organizationLocale = SupportedLocale::normalize(is_string($setting) ? $setting : null, 'ru');

        app()->setLocale(SupportedLocale::normalize(
            is_string($sessionLocale) ? $sessionLocale : null,
            $organizationLocale,
        ));

        return $next($request);
    }
}
