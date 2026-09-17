<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Commerce\Application\StartPurchaseCheckout;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PortalCommerceController extends Controller
{
    public function purchase(
        Request $request,
        ClientPortalContext $clientContext,
        OrganizationContext $organizationContext,
        StartPurchaseCheckout $checkout,
        int $serviceId,
    ): RedirectResponse {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:180', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);
        $client = $clientContext->client();
        $organization = $organizationContext->organization();
        $product = Service::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($serviceId)
            ->first();

        if (! $product instanceof Service) {
            throw (new ModelNotFoundException)->setModel(Service::class, [$serviceId]);
        }

        $result = $checkout->onlineProduct(
            organization: $organization,
            client: $client,
            product: $product,
            gateway: 'lava',
            idempotencyKey: (string) $data['idempotency_key'],
            buyerEmail: (string) $client->email,
            successfulReturnUrl: route('portal.finance.index'),
            failureReturnUrl: route('portal.finance.index'),
            cancelReturnUrl: route('portal.finance.index'),
        );

        if (! is_string($result->transaction->checkout_url) || $result->transaction->checkout_url === '') {
            return back()->withErrors(['payment' => 'Платёж уже проверяется. Обновите страницу немного позже.']);
        }

        return redirect()->away($result->transaction->checkout_url);
    }
}
