<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Commerce\Application\ApplyGiftCertificateToObligation;
use App\Modules\Commerce\Application\CreateGiftCertificateTransfer;
use App\Modules\Commerce\Application\ListClientGiftCertificates;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Finance\Application\ListClientFinance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class GiftCertificateController extends Controller
{
    public function index(
        ListClientGiftCertificates $certificates,
        ListClientFinance $finance,
        Request $request,
    ): Response {
        $obligations = collect($finance->handle(app()->getLocale())['obligations'])
            ->filter(static fn (array $obligation): bool => $obligation['available'] === true
                && (int) ($obligation['outstandingMinor'] ?? 0) > 0)
            ->values()
            ->all();

        $obligationData = collect($obligations);
        $certificateData = collect($certificates->handle(app()->getLocale()))
            ->map(function (array $certificate) use ($obligationData): array {
                $certificate['applyUrls'] = $obligationData->mapWithKeys(
                    fn (array $obligation): array => [
                        (string) $obligation['obligationId'] => route('portal.gift-certificates.apply', [
                            'certificateId' => $certificate['id'],
                            'obligationId' => $obligation['obligationId'],
                        ]),
                    ],
                )->all();

                return $certificate;
            })
            ->values()
            ->all();

        return Inertia::render('Portal/GiftCertificates', [
            'certificates' => $certificateData,
            'obligations' => $obligations,
            'transferUrl' => $request->session()->pull('gift_certificate_transfer_url'),
            'urls' => [
                'home' => route('portal.home'),
                'finance' => route('portal.finance.index'),
            ],
        ]);
    }

    public function transfer(
        ClientPortalContext $clientContext,
        CreateGiftCertificateTransfer $transfer,
        int $certificateId,
    ): RedirectResponse {
        $result = $transfer->handle(
            currentHolder: $clientContext->client(),
            certificate: GiftCertificate::query()
                ->where('organization_id', $clientContext->client()->organization_id)
                ->whereKey($certificateId)
                ->firstOrFail(),
        );

        return back()->with('gift_certificate_transfer_url', $result->url);
    }

    public function apply(
        Request $request,
        ClientPortalContext $clientContext,
        ApplyGiftCertificateToObligation $apply,
        int $certificateId,
        int $obligationId,
    ): RedirectResponse {
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:30', 'regex:/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/'],
            'currency' => ['required', 'string', 'max:3'],
            'idempotency_key' => ['required', 'string', 'max:180', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        try {
            $apply->handle(
                client: $clientContext->client(),
                certificate: $certificateId,
                obligationId: $obligationId,
                amount: (string) $data['amount'],
                currency: (string) $data['currency'],
                idempotencyKey: (string) $data['idempotency_key'],
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($exception->errors());
        }

        return back()->with('success', app()->getLocale() === 'en'
            ? 'Gift certificate applied.'
            : 'Сертификат применён к оплате.');
    }
}
