<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
use App\Modules\Referrals\Domain\Models\ReferralLinkVisit;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ListReferralPartnersForCrm
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    /** @return Builder<ReferralPartnerProfile> */
    public function query(User $actor): Builder
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $profileTable = (new ReferralPartnerProfile)->getTable();
        $linkIds = ReferralCampaignLink::query()
            ->whereColumn('organization_id', $profileTable.'.organization_id')
            ->whereColumn('partner_profile_id', $profileTable.'.id')
            ->select('id');
        $visits = ReferralLinkVisit::query()
            ->whereColumn('organization_id', $profileTable.'.organization_id')
            ->whereIn('campaign_link_id', $linkIds)
            ->selectRaw('COUNT(*)');
        $registrations = ReferralRelationship::query()
            ->whereColumn('organization_id', $profileTable.'.organization_id')
            ->whereColumn('referrer_client_id', $profileTable.'.client_id')
            ->selectRaw('COUNT(*)');
        $paidClients = ReferralRelationship::query()
            ->whereColumn('organization_id', $profileTable.'.organization_id')
            ->whereColumn('referrer_client_id', $profileTable.'.client_id')
            ->whereHas('commercialEvidence')
            ->selectRaw('COUNT(DISTINCT referred_client_id)');

        return ReferralPartnerProfile::query()
            ->where('organization_id', $organization->getKey())
            ->with('client:id,full_name,email,phone')
            ->addSelect([
                'visits_count' => $visits,
                'registrations_count' => $registrations,
                'paid_clients_count' => $paidClients,
            ])
            ->addSelect(DB::raw($this->balanceSummarySql().' AS balance_summary'))
            ->latest('activated_at');
    }

    /** @return literal-string */
    private function balanceSummarySql(): string
    {
        return DB::getDriverName() === 'pgsql'
            ? $this->postgresBalanceSummarySql()
            : $this->sqliteBalanceSummarySql();
    }

    /** @return literal-string */
    private function postgresBalanceSummarySql(): string
    {
        return "(SELECT COALESCE(jsonb_object_agg(currency, jsonb_build_object('available', available_minor, 'pending', pending_minor, 'paid', paid_minor)), '{}'::jsonb) FROM (SELECT currency,
                SUM(earned_minor) - SUM(reversed_minor) - SUM(pending_minor) - SUM(paid_minor) AS available_minor,
                SUM(pending_minor) AS pending_minor,
                SUM(paid_minor) AS paid_minor
            FROM (SELECT referral_reward_ledger_entries.currency,
                SUM(CASE WHEN referral_reward_ledger_entries.entry_type = 'earned' THEN referral_reward_ledger_entries.amount_minor ELSE 0 END) AS earned_minor,
                SUM(CASE WHEN referral_reward_ledger_entries.entry_type = 'reversed' THEN referral_reward_ledger_entries.amount_minor ELSE 0 END) AS reversed_minor,
                0 AS pending_minor,
                0 AS paid_minor
            FROM referral_reward_ledger_entries
            WHERE referral_reward_ledger_entries.organization_id = referral_partner_profiles.organization_id
                AND referral_reward_ledger_entries.beneficiary_client_id = referral_partner_profiles.client_id
            GROUP BY referral_reward_ledger_entries.currency
            UNION ALL
            SELECT referral_payout_requests.currency,
                0 AS earned_minor,
                0 AS reversed_minor,
                SUM(CASE WHEN referral_payout_requests.status IN ('requested', 'approved') THEN referral_payout_requests.amount_minor ELSE 0 END) AS pending_minor,
                SUM(CASE WHEN referral_payout_requests.status = 'paid' THEN referral_payout_requests.amount_minor ELSE 0 END) AS paid_minor
            FROM referral_payout_requests
            WHERE referral_payout_requests.organization_id = referral_partner_profiles.organization_id
                AND referral_payout_requests.beneficiary_client_id = referral_partner_profiles.client_id
            GROUP BY referral_payout_requests.currency) AS balance_components
            GROUP BY currency) AS partner_balances)";
    }

    /** @return literal-string */
    private function sqliteBalanceSummarySql(): string
    {
        return "(SELECT COALESCE(json_group_object(currency, json_object('available', available_minor, 'pending', pending_minor, 'paid', paid_minor)), '{}') FROM (SELECT currency,
                SUM(earned_minor) - SUM(reversed_minor) - SUM(pending_minor) - SUM(paid_minor) AS available_minor,
                SUM(pending_minor) AS pending_minor,
                SUM(paid_minor) AS paid_minor
            FROM (SELECT referral_reward_ledger_entries.currency,
                SUM(CASE WHEN referral_reward_ledger_entries.entry_type = 'earned' THEN referral_reward_ledger_entries.amount_minor ELSE 0 END) AS earned_minor,
                SUM(CASE WHEN referral_reward_ledger_entries.entry_type = 'reversed' THEN referral_reward_ledger_entries.amount_minor ELSE 0 END) AS reversed_minor,
                0 AS pending_minor,
                0 AS paid_minor
            FROM referral_reward_ledger_entries
            WHERE referral_reward_ledger_entries.organization_id = referral_partner_profiles.organization_id
                AND referral_reward_ledger_entries.beneficiary_client_id = referral_partner_profiles.client_id
            GROUP BY referral_reward_ledger_entries.currency
            UNION ALL
            SELECT referral_payout_requests.currency,
                0 AS earned_minor,
                0 AS reversed_minor,
                SUM(CASE WHEN referral_payout_requests.status IN ('requested', 'approved') THEN referral_payout_requests.amount_minor ELSE 0 END) AS pending_minor,
                SUM(CASE WHEN referral_payout_requests.status = 'paid' THEN referral_payout_requests.amount_minor ELSE 0 END) AS paid_minor
            FROM referral_payout_requests
            WHERE referral_payout_requests.organization_id = referral_partner_profiles.organization_id
                AND referral_payout_requests.beneficiary_client_id = referral_partner_profiles.client_id
            GROUP BY referral_payout_requests.currency) AS balance_components
            GROUP BY currency) AS partner_balances)";
    }
}
