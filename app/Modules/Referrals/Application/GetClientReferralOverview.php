<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;

final class GetClientReferralOverview
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly EnsureReferralIdentity $ensureIdentity,
    ) {}

    /** @return array{link: string, registrations: array<int, array<string, mixed>>} */
    public function handle(Client $client): array
    {
        $identity = $this->ensureIdentity->handle($client);
        $relationships = ReferralRelationship::query()
            ->where('organization_id', $this->context->id())
            ->where('referrer_client_id', $client->getKey())
            ->with(['referred:id,full_name'])
            ->withCount('commercialEvidence')
            ->withMax('commercialEvidence', 'observed_at')
            ->orderByDesc('registered_at')
            ->limit(50)
            ->get();

        return [
            'link' => route('portal.referral', ['referralCode' => $identity->public_code]),
            'registrations' => $relationships->map(static fn (ReferralRelationship $relationship): array => [
                'name' => $relationship->referred?->full_name ?: '—',
                'registeredAt' => $relationship->registered_at?->toIso8601String(),
                'financeEvidenceRecorded' => (int) ($relationship->commercial_evidence_count ?? 0) > 0,
                'financeEvidenceAt' => $relationship->commercial_evidence_max_observed_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
