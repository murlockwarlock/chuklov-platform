<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Models\ReferralConversionObservation;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Referrals\Domain\ValueObjects\PaidConversionEvidence;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

final class ObserveReferredPaidConversion
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(PaidConversionEvidence $evidence): ?ReferralConversionObservation
    {
        if (! $evidence->authoritativeSettled || $evidence->financeStatus !== 'settled') {
            return null;
        }

        $organization = $this->context->organization();
        abort_unless((int) $organization->getKey() === $evidence->organizationId, 404);

        return DB::transaction(function () use ($organization, $evidence): ?ReferralConversionObservation {
            $relationship = ReferralRelationship::query()
                ->where('organization_id', $organization->getKey())
                ->where('referred_client_id', $evidence->clientId)
                ->lockForUpdate()
                ->first();

            if (! $relationship instanceof ReferralRelationship) {
                return null;
            }

            $existing = ReferralConversionObservation::query()
                ->where('organization_id', $organization->getKey())
                ->where('referral_relationship_id', $relationship->getKey())
                ->where('financial_obligation_id', $evidence->obligationId)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ReferralConversionObservation) {
                return $existing;
            }

            $inserted = DB::table('referral_conversion_observations')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'referral_relationship_id' => $relationship->getKey(),
                'financial_obligation_id' => $evidence->obligationId,
                'financial_ledger_entry_id' => $evidence->ledgerEntryId,
                'finance_status' => $evidence->financeStatus,
                'observation_source' => $evidence->source,
                'observed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $observation = ReferralConversionObservation::query()
                ->where('organization_id', $organization->getKey())
                ->where('referral_relationship_id', $relationship->getKey())
                ->where('financial_obligation_id', $evidence->obligationId)
                ->lockForUpdate()
                ->first();

            if (! $observation instanceof ReferralConversionObservation) {
                $observation = ReferralConversionObservation::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('financial_ledger_entry_id', $evidence->ledgerEntryId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            if ($inserted === 0) {
                return $observation;
            }

            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'referral.conversion.observed',
                targetType: ReferralConversionObservation::class,
                targetId: (string) $observation->getKey(),
                metadata: [
                    'relationship_id' => $relationship->getKey(),
                    'obligation_id' => $evidence->obligationId,
                    'ledger_entry_id' => $evidence->ledgerEntryId,
                    'source' => $evidence->source,
                ],
            );

            return $observation->refresh();
        });
    }
}
