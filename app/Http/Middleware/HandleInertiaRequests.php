<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Http\Request;
use Inertia\Middleware;
use LogicException;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $client = $this->clientFromSession($request);

        $language = $client->language ?? $request->session()->get('portal.locale');
        $language = is_string($language) ? strtolower(trim($language)) : '';
        $locale = str_starts_with($language, 'ru') ? 'ru' : (str_starts_with($language, 'en') ? 'en' : null);
        $default = config('portal.default_locale', 'ru');
        $locale ??= in_array($default, ['ru', 'en'], true) ? $default : 'ru';

        app()->setLocale($locale);

        return [
            ...parent::share($request),
            'portal' => [
                'authenticated' => $client !== null,
                'clientName' => $client?->full_name,
                'locale' => $locale,
                'localeUrl' => route('portal.locale.update'),
                'urls' => [
                    'home' => route('portal.home'),
                    'services' => route('portal.services.index'),
                    'bookings' => route('portal.bookings.index'),
                    'profile' => route('portal.profile'),
                    'booking' => route('portal.bookings.create'),
                ],
            ],
        ];
    }

    private function clientFromSession(Request $request): ?Client
    {
        $clientId = $request->session()->get('client_portal.client_id');
        $isInteger = is_int($clientId) || (is_string($clientId) && ctype_digit($clientId));

        if (! $isInteger) {
            return null;
        }

        try {
            $organizationId = app(OrganizationContext::class)->id();
        } catch (LogicException) {
            $organizationId = config('tenancy.default_organization_id');
        }

        $isInteger = is_int($organizationId)
            || (is_string($organizationId) && ctype_digit($organizationId));

        if (! $isInteger) {
            return null;
        }

        return Client::query()
            ->where('organization_id', $organizationId)
            ->find((int) $clientId);
    }
}
