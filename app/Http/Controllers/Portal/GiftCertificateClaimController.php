<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Commerce\Application\ClaimGiftCertificate;
use App\Modules\Commerce\Application\GiftCertificateBalanceProjection;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificateClaim;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GiftCertificateClaimController extends Controller
{
    public function __construct(
        private readonly GiftCertificateBalanceProjection $balances,
    ) {}

    public function show(
        Request $request,
        ClientPortalContext $clientContext,
        OrganizationContext $organizationContext,
    ): Response {
        $token = $request->session()->pull('gift_certificate_claim_token');
        $claim = null;
        if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
            try {
                $claim = $this->findClaim($organizationContext, $token);
            } catch (NotFoundHttpException) {
                $token = null;
            }
        }

        return $this->render($token, $this->clientOrNull($clientContext) !== null, $claim);
    }

    public function preview(
        Request $request,
        OrganizationContext $organizationContext,
        ClientPortalContext $clientContext,
    ): Response {
        $token = $this->validatedToken($request);
        $claim = $this->findClaim($organizationContext, $token);
        $client = $this->clientOrNull($clientContext);
        if ($client === null) {
            $request->session()->put('gift_certificate_claim_token', $token);
        }

        return $this->render($token, $client !== null, $claim);
    }

    public function claim(
        Request $request,
        ClientPortalContext $clientContext,
        ClaimGiftCertificate $claim,
    ): RedirectResponse {
        $token = $this->validatedToken($request);
        try {
            $client = $clientContext->client();
        } catch (LogicException) {
            $request->session()->put('gift_certificate_claim_token', $token);

            return to_route('portal.home');
        }

        try {
            $claim->handle($client, $token);
        } catch (ValidationException $exception) {
            $request->session()->put('gift_certificate_claim_token', $token);

            return to_route('gift-certificates.claim')->withErrors($exception->errors());
        }

        return to_route('portal.gift-certificates.index')
            ->with('success', app()->getLocale() === 'en'
                ? 'Gift certificate received.'
                : 'Сертификат получен.');
    }

    private function render(?string $token, bool $authenticated, ?GiftCertificateClaim $claim): Response
    {
        $certificate = $claim?->certificate;
        $certificateData = null;
        if ($certificate instanceof GiftCertificate) {
            $balance = $this->balances->balance($certificate);
            $certificateData = [
                'originalAmountMinor' => (int) $certificate->original_amount_minor,
                'balanceMinor' => $balance->minorUnits(),
                'currency' => $certificate->currency->value,
                'purchaserName' => $certificate->purchaser?->full_name,
            ];
        }

        return Inertia::render('Portal/GiftCertificateClaim', [
            'token' => $token,
            'authenticated' => $authenticated,
            'certificate' => $certificateData,
            'urls' => [
                'home' => route('portal.home'),
                'preview' => route('gift-certificates.claim.preview'),
                'claim' => route('gift-certificates.claim.submit'),
            ],
        ]);
    }

    private function findClaim(OrganizationContext $organizationContext, string $token): GiftCertificateClaim
    {
        $claim = GiftCertificateClaim::query()
            ->where('organization_id', $organizationContext->id())
            ->where('token_hash', hash('sha256', $token))
            ->where('status', 'pending')
            ->with(['certificate.purchaser'])
            ->first();
        $certificate = $claim?->certificate;
        if (! $claim instanceof GiftCertificateClaim
            || ! $certificate instanceof GiftCertificate
            || (int) $certificate->current_holder_client_id !== (int) $claim->initiated_by_client_id
            || ! $this->balances->balance($certificate)->isPositive()) {
            abort(404);
        }

        return $claim;
    }

    private function validatedToken(Request $request): string
    {
        $token = $request->input('token');
        if (! is_string($token)) {
            throw ValidationException::withMessages([
                'token' => 'Ссылка на сертификат недействительна или уже использована.',
            ]);
        }

        $token = trim($token);
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw ValidationException::withMessages([
                'token' => 'Ссылка на сертификат недействительна или уже использована.',
            ]);
        }

        return $token;
    }

    private function clientOrNull(ClientPortalContext $clientContext): ?Client
    {
        try {
            return $clientContext->client();
        } catch (LogicException) {
            return null;
        }
    }
}
