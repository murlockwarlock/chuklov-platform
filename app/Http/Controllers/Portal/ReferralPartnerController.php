<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReferralCampaignLinkRequest;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\CreateReferralCampaignLink;
use App\Modules\Referrals\Application\DeactivateReferralCampaignLink;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use Illuminate\Http\RedirectResponse;
use LogicException;

final class ReferralPartnerController extends Controller
{
    public function activate(
        ClientPortalContext $context,
        ActivateReferralPartner $activate,
    ): RedirectResponse {
        try {
            $activate->handle($context->client(), 'portal');
        } catch (LogicException) {
            abort(401);
        }

        return back()->with('success', 'Партнёрская программа подключена.');
    }

    public function store(
        CreateReferralCampaignLinkRequest $request,
        ClientPortalContext $context,
        CreateReferralCampaignLink $create,
    ): RedirectResponse {
        try {
            $create->handle(
                client: $context->client(),
                name: (string) $request->validated('name'),
                channel: ReferralCampaignChannel::from((string) $request->validated('channel')),
            );
        } catch (LogicException) {
            abort(401);
        }

        return back()->with('success', 'Ссылка создана.');
    }

    public function disable(
        int $campaignLinkId,
        ClientPortalContext $context,
        DeactivateReferralCampaignLink $disable,
    ): RedirectResponse {
        try {
            $disable->handle($campaignLinkId, $context->client());
        } catch (LogicException) {
            abort(401);
        }

        return back()->with('success', 'Ссылка отключена.');
    }
}
