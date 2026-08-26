<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Referrals\Application\GetClientReferralOverview;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class ReferralController extends Controller
{
    public function __invoke(ClientPortalContext $context, GetClientReferralOverview $overview): Response
    {
        try {
            $client = $context->client();
        } catch (LogicException) {
            abort(401);
        }

        return Inertia::render('Portal/Referrals', [
            'referrals' => $overview->handle($client),
        ]);
    }
}
