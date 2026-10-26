<?php

namespace App\Http\Middleware;

use App\Modules\ClientPortal\Application\GetClientAvailableBonus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Support\SupportedLocale;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use LogicException;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $client = $this->clientFromSession($request);

        $language = $client->language ?? $request->session()->get('portal.locale');
        $locale = SupportedLocale::normalize(
            is_string($language) ? $language : null,
            (string) config('portal.default_locale', 'ru'),
        );

        $successMessage = $request->session()->get('success');
        if (is_string($successMessage) && trim($successMessage) !== '') {
            Inertia::flash('success', $successMessage);
        }
        $errorMessage = $request->session()->get('error');
        if (is_string($errorMessage) && trim($errorMessage) !== '') {
            Inertia::flash('error', $errorMessage);
        }

        app()->setLocale($locale);
        $bonus = app(GetClientAvailableBonus::class);

        return [
            ...parent::share($request),
            'flash' => [
                'payout' => fn (): ?array => $this->payoutFeedback($request->session()->get('payout_feedback')),
                'giftCertificateTransferUrl' => fn (): ?string => is_string($request->session()->get('gift_certificate_transfer_url'))
                    ? $request->session()->get('gift_certificate_transfer_url')
                    : null,
            ],
            'portal' => [
                'authenticated' => $client !== null,
                'clientName' => $client?->full_name,
                'timezone' => $client?->timezone ?: (string) config('app.timezone', 'UTC'),
                'confirmedFirstName' => $client?->name_confirmed_at !== null ? $client->first_name : null,
                'availableBonus' => fn (): ?string => $client === null ? null : $bonus->handle($client),
                'bonusCurrencyUnavailable' => fn (): bool => $client !== null && $bonus->currencyUnavailable($client),
                'isPartner' => $client?->referralPartnerProfile?->isActive() === true,
                'locale' => $locale,
                'localeUrl' => route('portal.locale.update'),
                'urls' => [
                    'home' => route('portal.home'),
                    'services' => route('portal.services.index'),
                    'bookings' => route('portal.bookings.index'),
                    'health' => route('portal.health'),
                    'more' => route('portal.more'),
                    'finance' => route('portal.finance.index'),
                    'giftCertificates' => route('portal.gift-certificates.index'),
                    'surveys' => route('portal.surveys.index'),
                    'companion' => route('portal.companion'),
                    'tracker' => route('portal.tracker'),
                    'profile' => route('portal.profile'),
                    'medicalProfile' => route('portal.medical-profile'),
                    'communities' => route('portal.section', ['section' => 'communities']),
                    'referrals' => route('portal.referrals'),
                    'feedback' => route('portal.feedback'),
                    'attribution' => route('portal.attribution'),
                    'booking' => route('portal.bookings.create'),
                    'b2b' => route('portal.b2b'),
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

    /** @return array<string, string|null>|null */
    private function payoutFeedback(mixed $feedback): ?array
    {
        if (! is_array($feedback)
            || ! is_string($feedback['message'] ?? null)
            || ! is_string($feedback['amount'] ?? null)
            || ! is_string($feedback['status'] ?? null)) {
            return null;
        }

        return [
            'message' => $feedback['message'],
            'amount' => $feedback['amount'],
            'currency' => is_string($feedback['currency'] ?? null) ? $feedback['currency'] : null,
            'status' => $feedback['status'],
            'requested_at' => is_string($feedback['requested_at'] ?? null) ? $feedback['requested_at'] : null,
        ];
    }
}
