<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\B2B\Application\GetB2bSalesCallHostLaunchUrl;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AdminB2bSalesCallHostLaunchController extends Controller
{
    public function __invoke(
        int $salesCallId,
        Request $request,
        OrganizationContext $organizationContext,
        GetB2bSalesCallHostLaunchUrl $hostLaunchUrl,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $salesCall = B2bSalesCall::query()
            ->where('organization_id', $organizationContext->id())
            ->whereKey($salesCallId)
            ->firstOrFail();

        try {
            return redirect()->away($hostLaunchUrl->handle($actor, $salesCall));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }
    }
}
