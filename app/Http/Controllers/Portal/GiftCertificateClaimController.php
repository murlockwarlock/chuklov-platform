<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Commerce\Application\ClaimGiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificateClaim;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

final class GiftCertificateClaimController extends Controller
{
    public function show(
        Request $request,
        OrganizationContext $organizationContext,
        ClientPortalContext $clientContext,
        string $token,
    ): Response {
        $claim = $this->findClaim($organizationContext, $token);
        $certificate = $claim->certificate;
        if (! $certificate instanceof GiftCertificate) {
            abort(404);
        }
        $client = null;
        try {
            $client = $clientContext->client();
        } catch (LogicException) {
            $request->session()->put('gift_certificate_claim_token', $token);
        }

        return Inertia::render('Portal/GiftCertificateClaim', [
            'token' => $token,
            'authenticated' => $client !== null,
            'certificate' => [
                'originalAmountMinor' => (int) $certificate->original_amount_minor,
                'currency' => $certificate->currency->value,
                'purchaserName' => $certificate->purchaser?->full_name,
            ],
            'urls' => [
                'home' => route('portal.home'),
                'claim' => route('gift-certificates.claim', ['token' => $token]),
            ],
        ]);
    }

    public function claim(
        Request $request,
        ClientPortalContext $clientContext,
        ClaimGiftCertificate $claim,
        string $token,
    ): RedirectResponse {
        try {
            $client = $clientContext->client();
        } catch (LogicException) {
            $request->session()->put('gift_certificate_claim_token', $token);

            return to_route('portal.home');
        }

        try {
            $claim->handle($client, $token);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($exception->errors());
        }

        return to_route('portal.gift-certificates.index')
            ->with('success', app()->getLocale() === 'en'
                ? 'Gift certificate received.'
                : 'Сертификат получен.');
    }

    private function findClaim(OrganizationContext $organizationContext, string $token): GiftCertificateClaim
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            abort(404);
        }

        $claim = GiftCertificateClaim::query()
            ->where('organization_id', $organizationContext->id())
            ->where('token_hash', hash('sha256', $token))
            ->where('status', 'pending')
            ->with(['certificate.purchaser'])
            ->first();
        if (! $claim instanceof GiftCertificateClaim) {
            abort(404);
        }

        return $claim;
    }
}
