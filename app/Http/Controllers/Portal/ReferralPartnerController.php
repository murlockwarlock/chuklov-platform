<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReferralCampaignLinkRequest;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\PortalClientMessages;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\CreateReferralCampaignLink;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ReferralPartnerController extends Controller
{
    public function activate(
        ClientPortalContext $context,
        ActivateReferralPartner $activate,
        PortalClientMessages $messages,
    ): RedirectResponse {
        try {
            $activate->handle($context->client(), 'portal');
        } catch (LogicException) {
            abort(401);
        }

        return back()->with('success', $messages->message('referral_partner_activated'));
    }

    public function store(
        CreateReferralCampaignLinkRequest $request,
        ClientPortalContext $context,
        CreateReferralCampaignLink $create,
        PortalClientMessages $messages,
    ): RedirectResponse {
        try {
            $create->handle(
                client: $context->client(),
                name: (string) $request->validated('name'),
                channel: ReferralCampaignChannel::from((string) $request->validated('channel')),
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($messages->validationException('referral_link', $exception));
        } catch (LogicException) {
            abort(401);
        }

        return back()->with('success', $messages->message('referral_link_created'));
    }
}
