<?php

namespace App\Modules\Identity\Application;

use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Finance\Domain\Contracts\ReceiptStorage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PurgeStagingClientAccountData
{
    private const MUTATION_TRIGGERS = [
        ['booking_events', 'booking_events_immutable'],
        ['financial_ledger_entries', 'financial_ledger_entries_immutable'],
        ['referral_reward_ledger_entries', 'ref_reward_ledger_immutable'],
        ['referral_reward_program_versions', 'ref_reward_version_immutable'],
        ['referral_payout_request_events', 'ref_payout_event_immutable'],
    ];

    public function __construct(
        private readonly AttachmentStorageInterface $attachments,
        private readonly ReceiptStorage $receipts,
    ) {}

    /**
     * @return array{deleted_record_count: int, attachment_paths: array<int, string>, receipt_paths: array<int, string>}
     */
    public function handle(Organization $organization, Client $client): array
    {
        $organizationId = (int) $organization->getKey();
        $clientId = (int) $client->getKey();
        $bookingIds = $this->idsByClient('bookings', 'id', $organizationId, 'client_id', $clientId);
        $b2bLeadIds = $this->idsByClient('b2b_leads', 'id', $organizationId, 'client_id', $clientId);
        $b2bSalesCallIds = $this->idsByClient('b2b_sales_calls', 'id', $organizationId, 'client_id', $clientId);
        $b2bSalesCallIds = $this->mergeIds(
            $b2bSalesCallIds,
            $this->idsByValues('b2b_sales_calls', 'id', $organizationId, 'lead_id', $b2bLeadIds),
        );
        $medicalSessionIds = $this->mergeIds(
            $this->idsByClient('medical_sessions', 'id', $organizationId, 'client_id', $clientId),
            $this->idsByValues('medical_sessions', 'id', $organizationId, 'booking_id', $bookingIds),
        );
        $medicalAttachmentRows = DB::table('medical_attachments')
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->get(['id', 'storage_path']);
        $medicalAttachmentIds = $this->normalizeIds($medicalAttachmentRows->pluck('id')->all());
        $attachmentPaths = array_values(array_unique(array_filter(array_map(
            static fn (mixed $path): string => (string) $path,
            $medicalAttachmentRows->pluck('storage_path')->all(),
        ))));
        $financialObligationIds = $this->mergeIds(
            $this->idsByClient('financial_obligations', 'id', $organizationId, 'client_id', $clientId),
            $this->idsByValues('financial_obligations', 'id', $organizationId, 'booking_id', $bookingIds),
        );
        $financialLedgerEntryIds = $this->idsByValues(
            'financial_ledger_entries',
            'id',
            $organizationId,
            'obligation_id',
            $financialObligationIds,
        );
        $financialLedgerEntryIds = $this->expandIds(
            'financial_ledger_entries',
            $organizationId,
            'id',
            'corrects_ledger_entry_id',
            $financialLedgerEntryIds,
        );
        $receiptPaths = $this->pathsByValues(
            'financial_receipts',
            $organizationId,
            'ledger_entry_id',
            $financialLedgerEntryIds,
        );
        $paymentGatewayTransactionIds = $this->mergeIds(
            $this->idsByValues(
                'payment_gateway_transactions',
                'id',
                $organizationId,
                'obligation_id',
                $financialObligationIds,
            ),
            $this->idsByValues(
                'payment_gateway_transactions',
                'id',
                $organizationId,
                'ledger_entry_id',
                $financialLedgerEntryIds,
            ),
        );
        $referralIdentityIds = $this->idsByClient(
            'client_referral_identities',
            'id',
            $organizationId,
            'client_id',
            $clientId,
        );
        $referralPartnerProfileIds = $this->idsByClient(
            'referral_partner_profiles',
            'id',
            $organizationId,
            'client_id',
            $clientId,
        );
        $referralCampaignLinkIds = $this->mergeIds(
            $this->idsByClient('referral_campaign_links', 'id', $organizationId, 'partner_client_id', $clientId),
            $this->idsByValues('referral_campaign_links', 'id', $organizationId, 'referral_identity_id', $referralIdentityIds),
        );
        $referralCampaignLinkIds = $this->mergeIds(
            $referralCampaignLinkIds,
            $this->idsByValues('referral_campaign_links', 'id', $organizationId, 'partner_profile_id', $referralPartnerProfileIds),
        );
        $referralRelationshipIds = $this->mergeIds(
            $this->idsByClient('referral_relationships', 'id', $organizationId, 'referrer_client_id', $clientId),
            $this->idsByClient('referral_relationships', 'id', $organizationId, 'referred_client_id', $clientId),
        );
        $referralRelationshipIds = $this->mergeIds(
            $referralRelationshipIds,
            $this->idsByValues('referral_relationships', 'id', $organizationId, 'referral_campaign_link_id', $referralCampaignLinkIds),
        );
        $referralEvidenceIds = $this->mergeIds(
            $this->idsByClient('referral_commercial_evidence', 'id', $organizationId, 'referred_client_id', $clientId),
            $this->idsByValues('referral_commercial_evidence', 'id', $organizationId, 'referral_relationship_id', $referralRelationshipIds),
        );
        $referralEvidenceIds = $this->mergeIds(
            $referralEvidenceIds,
            $this->idsByValues('referral_commercial_evidence', 'id', $organizationId, 'financial_obligation_id', $financialObligationIds),
        );
        $referralEvidenceIds = $this->mergeIds(
            $referralEvidenceIds,
            $this->idsByValues('referral_commercial_evidence', 'id', $organizationId, 'financial_ledger_entry_id', $financialLedgerEntryIds),
        );
        $integrationEventIds = $this->idsByValues(
            'referral_commercial_evidence',
            'integration_event_id',
            $organizationId,
            'id',
            $referralEvidenceIds,
        );
        $referralRewardLedgerEntryIds = $this->mergeIds(
            $this->idsByClient('referral_reward_ledger_entries', 'id', $organizationId, 'beneficiary_client_id', $clientId),
            $this->idsByClient('referral_reward_ledger_entries', 'id', $organizationId, 'referred_client_id', $clientId),
        );
        $referralRewardLedgerEntryIds = $this->mergeIds(
            $referralRewardLedgerEntryIds,
            $this->idsByValues('referral_reward_ledger_entries', 'id', $organizationId, 'referral_relationship_id', $referralRelationshipIds),
        );
        $referralRewardLedgerEntryIds = $this->mergeIds(
            $referralRewardLedgerEntryIds,
            $this->idsByValues('referral_reward_ledger_entries', 'id', $organizationId, 'referral_commercial_evidence_id', $referralEvidenceIds),
        );
        $referralRewardLedgerEntryIds = $this->mergeIds(
            $referralRewardLedgerEntryIds,
            $this->idsByValues('referral_reward_ledger_entries', 'id', $organizationId, 'financial_obligation_id', $financialObligationIds),
        );
        $referralRewardLedgerEntryIds = $this->mergeIds(
            $referralRewardLedgerEntryIds,
            $this->idsByValues('referral_reward_ledger_entries', 'id', $organizationId, 'financial_ledger_entry_id', $financialLedgerEntryIds),
        );
        $referralRewardLedgerEntryIds = $this->expandIds(
            'referral_reward_ledger_entries',
            $organizationId,
            'id',
            'reverses_entry_id',
            $referralRewardLedgerEntryIds,
        );
        $referralPayoutRequestIds = $this->idsByClient(
            'referral_payout_requests',
            'id',
            $organizationId,
            'beneficiary_client_id',
            $clientId,
        );

        $disabledTriggers = $this->disableMutationTriggers();

        try {
            $deletedRecordCount = 0;
            $deletedRecordCount += $this->deleteByValues('unavailable_periods', $organizationId, 'b2b_sales_call_id', $b2bSalesCallIds);
            $deletedRecordCount += $this->deleteByClientOrValues('booking_events', $organizationId, 'actor_client_id', $clientId, 'booking_id', $bookingIds);
            $deletedRecordCount += $this->deleteByClientOrValues('booking_idempotency_keys', $organizationId, 'actor_client_id', $clientId, 'booking_id', $bookingIds);
            $deletedRecordCount += $this->deleteByClientOrValues('scenario_actions', $organizationId, 'client_id', $clientId, 'booking_id', $bookingIds);
            $deletedRecordCount += $this->deleteByClientOrValues('companion_message_attachments', $organizationId, 'client_id', $clientId, 'medical_attachment_id', $medicalAttachmentIds);
            $deletedRecordCount += $this->deleteByClientOrValues('medical_session_attachments', $organizationId, 'client_id', $clientId, 'medical_session_id', $medicalSessionIds);
            $deletedRecordCount += $this->deleteByValues('medical_session_attachments', $organizationId, 'medical_attachment_id', $medicalAttachmentIds);
            $deletedRecordCount += $this->deleteByClientOrValues('medical_sessions', $organizationId, 'client_id', $clientId, 'booking_id', $bookingIds);
            $deletedRecordCount += $this->deleteByClient('medical_profiles', $organizationId, 'client_id', $clientId);
            $deletedRecordCount += $this->deleteByClientOrValues('survey_comparisons', $organizationId, 'client_id', $clientId, 'previous_attempt_id', $this->idsByClient('survey_attempts', 'id', $organizationId, 'client_id', $clientId));
            $deletedRecordCount += $this->deleteByClient('survey_reports', $organizationId, 'client_id', $clientId);
            $deletedRecordCount += $this->deleteByClient('survey_attempts', $organizationId, 'client_id', $clientId);
            $deletedRecordCount += $this->deleteByClientOrValues('tracker_task_entries', $organizationId, 'client_id', $clientId, 'tracker_task_id', $this->idsByClient('tracker_tasks', 'id', $organizationId, 'client_id', $clientId));
            $deletedRecordCount += $this->deleteByClient('tracker_tasks', $organizationId, 'client_id', $clientId);
            $deletedRecordCount += $this->deleteByClient('tracker_entitlements', $organizationId, 'client_id', $clientId);
            $deletedRecordCount += $this->deleteByClient('tracker_check_ins', $organizationId, 'client_id', $clientId);
            $broadcastRecipientIds = $this->idsByClient('broadcast_recipients', 'id', $organizationId, 'client_id', $clientId);
            $deletedRecordCount += $this->deleteByValues('broadcast_delivery_attempts', $organizationId, 'recipient_id', $broadcastRecipientIds);
            $deletedRecordCount += $this->deleteByClient('broadcast_recipients', $organizationId, 'client_id', $clientId);
            $deletedRecordCount += $this->deleteByClient('client_acquisition_registrations', $organizationId, 'client_id', $clientId);
            $deletedRecordCount += $this->deleteByClient('client_telegram_authentication_requests', $organizationId, 'client_id', $clientId);
            $deletedRecordCount += $this->deleteByValues('referral_payout_request_events', $organizationId, 'payout_request_id', $referralPayoutRequestIds);
            $deletedRecordCount += $this->deleteSelfReferencing('referral_reward_ledger_entries', $organizationId, 'id', 'reverses_entry_id', $referralRewardLedgerEntryIds);
            $deletedRecordCount += $this->deleteByValues('referral_commercial_evidence', $organizationId, 'id', $referralEvidenceIds);
            $deletedRecordCount += $this->deleteByValues('integration_events', $organizationId, 'id', $integrationEventIds);
            $deletedRecordCount += $this->deleteByValues('referral_relationships', $organizationId, 'id', $referralRelationshipIds);
            $referralLinkVisitIds = $this->idsByValues('referral_link_visits', 'id', $organizationId, 'campaign_link_id', $referralCampaignLinkIds);
            $deletedRecordCount += $this->deleteByValues('referral_link_visits', $organizationId, 'id', $referralLinkVisitIds);
            $deletedRecordCount += $this->deleteByValues('referral_campaign_links', $organizationId, 'id', $referralCampaignLinkIds);
            $deletedRecordCount += DB::table('referral_reward_program_versions')
                ->where('organization_id', $organizationId)
                ->whereIn('partner_profile_id', $referralPartnerProfileIds)
                ->update(['partner_profile_id' => null]);
            $deletedRecordCount += $this->deleteByValues('referral_payout_requests', $organizationId, 'id', $referralPayoutRequestIds);
            $deletedRecordCount += $this->deleteByValues('referral_partner_profiles', $organizationId, 'id', $referralPartnerProfileIds);
            $deletedRecordCount += $this->deleteByValues('client_referral_identities', $organizationId, 'id', $referralIdentityIds);
            $deletedRecordCount += $this->deleteByValues('financial_receipts', $organizationId, 'ledger_entry_id', $financialLedgerEntryIds);
            $deletedRecordCount += $this->deleteByValues('payment_gateway_events', $organizationId, 'gateway_transaction_id', $paymentGatewayTransactionIds);
            $deletedRecordCount += $this->deleteByValues('payment_gateway_transactions', $organizationId, 'id', $paymentGatewayTransactionIds);
            $deletedRecordCount += $this->deleteFinanceIdempotencyKeys($organizationId, $financialObligationIds, $financialLedgerEntryIds);
            $deletedRecordCount += $this->deleteSelfReferencing('financial_ledger_entries', $organizationId, 'id', 'corrects_ledger_entry_id', $financialLedgerEntryIds);
            $deletedRecordCount += $this->deleteByValues('financial_obligations', $organizationId, 'id', $financialObligationIds);
            $deletedRecordCount += $this->deleteByValues('bookings', $organizationId, 'id', $bookingIds);
            $deletedRecordCount += $this->deleteByValues('ai_runs', $organizationId, 'id', $this->idsByClient('ai_runs', 'id', $organizationId, 'client_id', $clientId));
            $deletedRecordCount += $this->deleteByValues('b2b_sales_calls', $organizationId, 'id', $b2bSalesCallIds);
            $deletedRecordCount += $this->deleteByValues('b2b_leads', $organizationId, 'id', $b2bLeadIds);
            $deletedRecordCount += $this->deleteByClient('client_attributions', $organizationId, 'client_id', $clientId);
            $deletedRecordCount += $this->deleteByClient('feedback_submissions', $organizationId, 'client_id', $clientId);
            $deletedRecordCount += $this->deleteByClient('pre_auth_attributions', $organizationId, 'consumed_client_id', $clientId);
            $deletedRecordCount += $this->deleteByValues('medical_attachments', $organizationId, 'id', $medicalAttachmentIds);

            return [
                'deleted_record_count' => $deletedRecordCount,
                'attachment_paths' => $attachmentPaths,
                'receipt_paths' => $receiptPaths,
            ];
        } finally {
            $this->enableMutationTriggers($disabledTriggers);
        }
    }

    /**
     * @param  array{deleted_record_count: int, attachment_paths: array<int, string>, receipt_paths: array<int, string>}  $purge
     */
    public function deleteStorage(array $purge): void
    {
        foreach ($purge['attachment_paths'] as $path) {
            $this->attachments->delete($path);
        }

        foreach ($purge['receipt_paths'] as $path) {
            $this->receipts->delete($path);
        }
    }

    /** @return array<int, int> */
    private function idsByClient(string $table, string $idColumn, int $organizationId, string $clientColumn, int $clientId): array
    {
        return $this->normalizeIds(DB::table($table)
            ->where('organization_id', $organizationId)
            ->where($clientColumn, $clientId)
            ->pluck($idColumn)
            ->all());
    }

    /**
     * @param  array<int, int>  $values
     * @return array<int, int>
     */
    private function idsByValues(string $table, string $idColumn, int $organizationId, string $column, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return $this->normalizeIds(DB::table($table)
            ->where('organization_id', $organizationId)
            ->whereIn($column, $values)
            ->pluck($idColumn)
            ->all());
    }

    /**
     * @param  array<int, int>  $values
     * @return array<int, string>
     */
    private function pathsByValues(string $table, int $organizationId, string $column, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $path): string => (string) $path,
            DB::table($table)->where('organization_id', $organizationId)->whereIn($column, $values)->pluck('path')->all(),
        ))));
    }

    /** @param array<int, int> $values */
    private function deleteByValues(string $table, int $organizationId, string $column, array $values): int
    {
        if ($values === []) {
            return 0;
        }

        return DB::table($table)
            ->where('organization_id', $organizationId)
            ->whereIn($column, $values)
            ->delete();
    }

    private function deleteByClient(string $table, int $organizationId, string $clientColumn, int $clientId): int
    {
        return DB::table($table)
            ->where('organization_id', $organizationId)
            ->where($clientColumn, $clientId)
            ->delete();
    }

    /** @param array<int, int> $values */
    private function deleteByClientOrValues(string $table, int $organizationId, string $clientColumn, int $clientId, string $valueColumn, array $values): int
    {
        $deleted = $this->deleteByClient($table, $organizationId, $clientColumn, $clientId);

        return $deleted + $this->deleteByValues($table, $organizationId, $valueColumn, $values);
    }

    /**
     * @param  array<int, int>  $first
     * @param  array<int, int>  $second
     * @return array<int, int>
     */
    private function mergeIds(array $first, array $second): array
    {
        return $this->normalizeIds(array_merge($first, $second));
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function expandIds(string $table, int $organizationId, string $idColumn, string $parentColumn, array $ids): array
    {
        $known = $this->normalizeIds($ids);

        while (true) {
            $newIds = $this->idsByValues($table, $idColumn, $organizationId, $parentColumn, $known);
            $expanded = $this->mergeIds($known, $newIds);

            if (count($expanded) === count($known)) {
                return $known;
            }

            $known = $expanded;
        }
    }

    /** @param array<int, int> $ids */
    private function deleteSelfReferencing(string $table, int $organizationId, string $idColumn, string $parentColumn, array $ids): int
    {
        $remaining = array_fill_keys($this->normalizeIds($ids), true);
        $deleted = 0;

        while ($remaining !== []) {
            $remainingIds = array_map(static fn (int|string $id): int => (int) $id, array_keys($remaining));
            $leafIds = [];

            foreach ($remainingIds as $id) {
                if (! DB::table($table)
                    ->where('organization_id', $organizationId)
                    ->where($parentColumn, $id)
                    ->whereIn($idColumn, $remainingIds)
                    ->exists()) {
                    $leafIds[] = $id;
                }
            }

            if ($leafIds === []) {
                throw new RuntimeException("Cannot safely delete cyclic {$table} records.");
            }

            $deleted += $this->deleteByValues($table, $organizationId, $idColumn, $leafIds);

            foreach ($leafIds as $id) {
                unset($remaining[$id]);
            }
        }

        return $deleted;
    }

    /**
     * @param  array<int, int>  $obligationIds
     * @param  array<int, int>  $ledgerEntryIds
     */
    private function deleteFinanceIdempotencyKeys(int $organizationId, array $obligationIds, array $ledgerEntryIds): int
    {
        if ($obligationIds === [] && $ledgerEntryIds === []) {
            return 0;
        }

        $query = DB::table('finance_idempotency_keys')
            ->where('organization_id', $organizationId)
            ->where(function (Builder $query) use ($obligationIds, $ledgerEntryIds): void {
                if ($obligationIds !== []) {
                    $query->orWhere(function (Builder $query) use ($obligationIds): void {
                        $query->where('subject_type', 'App\\Modules\\Finance\\Domain\\Models\\FinancialObligation')
                            ->whereIn('subject_id', $obligationIds);
                    });
                    $query->orWhere(function (Builder $query) use ($obligationIds): void {
                        $query->where('result_type', 'App\\Modules\\Finance\\Domain\\Models\\FinancialObligation')
                            ->whereIn('result_id', $obligationIds);
                    });
                }

                if ($ledgerEntryIds !== []) {
                    $query->orWhere(function (Builder $query) use ($ledgerEntryIds): void {
                        $query->where('subject_type', 'App\\Modules\\Finance\\Domain\\Models\\FinancialLedgerEntry')
                            ->whereIn('subject_id', $ledgerEntryIds);
                    });
                    $query->orWhere(function (Builder $query) use ($ledgerEntryIds): void {
                        $query->where('result_type', 'App\\Modules\\Finance\\Domain\\Models\\FinancialLedgerEntry')
                            ->whereIn('result_id', $ledgerEntryIds);
                    });
                }
            });

        return $query->delete();
    }

    /** @return array<int, string> */
    private function disableMutationTriggers(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        $disabled = [];

        try {
            foreach (self::MUTATION_TRIGGERS as [$table, $trigger]) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
                $disabled[] = $table.'|'.$trigger;
            }
        } catch (\Throwable $exception) {
            $this->enableMutationTriggers($disabled);
            throw $exception;
        }

        return $disabled;
    }

    /** @param array<int, string> $disabledTriggers */
    private function enableMutationTriggers(array $disabledTriggers): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse($disabledTriggers) as $disabledTrigger) {
            [$table, $trigger] = explode('|', $disabledTrigger, 2);
            DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $ids)));
    }
}
