<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_reward_ledger_entries', function (Blueprint $table): void {
            $table->string('reward_category', 24)->default('service_credit')->after('entry_type');
            $table->index(
                ['organization_id', 'beneficiary_client_id', 'reward_category', 'currency', 'occurred_at'],
                'ref_reward_ledger_org_beneficiary_category_index',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS ref_reward_ledger_immutable ON referral_reward_ledger_entries');
            DB::statement('ALTER TABLE referral_reward_ledger_entries DROP CONSTRAINT IF EXISTS ref_reward_ledger_shape_check');
        }

        DB::table('referral_reward_ledger_entries')
            ->where('entry_type', 'manual_credit')
            ->update(['reward_category' => 'partner_cash']);

        $partnerProfiles = DB::table('referral_partner_profiles')
            ->get(['organization_id', 'client_id', 'activated_at', 'deactivated_at'])
            ->groupBy(fn (object $profile): string => $profile->organization_id.':'.$profile->client_id);
        $partnerVersionIds = DB::table('referral_reward_program_versions')
            ->whereNotNull('partner_profile_id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $partnerVersionIds = array_fill_keys($partnerVersionIds, true);

        DB::table('referral_reward_ledger_entries')
            ->where('entry_type', 'earned')
            ->orderBy('id')
            ->get(['id', 'organization_id', 'beneficiary_client_id', 'reward_program_version_id', 'occurred_at'])
            ->each(function (object $entry) use ($partnerProfiles, $partnerVersionIds): void {
                $isPartner = isset($partnerVersionIds[(int) $entry->reward_program_version_id]);
                $profiles = $partnerProfiles->get($entry->organization_id.':'.$entry->beneficiary_client_id, collect());
                $observedAt = CarbonImmutable::parse((string) $entry->occurred_at);

                foreach ($profiles as $profile) {
                    $activatedAt = CarbonImmutable::parse((string) $profile->activated_at);
                    $deactivatedAt = $profile->deactivated_at === null
                        ? null
                        : CarbonImmutable::parse((string) $profile->deactivated_at);

                    if ($activatedAt->lessThanOrEqualTo($observedAt)
                        && ($deactivatedAt === null || $deactivatedAt->greaterThan($observedAt))) {
                        $isPartner = true;
                        break;
                    }
                }

                if ($isPartner) {
                    DB::table('referral_reward_ledger_entries')
                        ->where('id', $entry->id)
                        ->update(['reward_category' => 'partner_cash']);
                }
            });

        $categories = DB::table('referral_reward_ledger_entries')->pluck('reward_category', 'id');
        DB::table('referral_reward_ledger_entries')
            ->where('entry_type', 'reversed')
            ->orderBy('id')
            ->get(['id', 'reverses_entry_id'])
            ->each(function (object $entry) use ($categories): void {
                $category = $categories->get((int) $entry->reverses_entry_id);

                if (is_string($category)) {
                    DB::table('referral_reward_ledger_entries')
                        ->where('id', $entry->id)
                        ->update(['reward_category' => $category]);
                }
            });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE referral_reward_ledger_entries ADD CONSTRAINT ref_reward_ledger_shape_check CHECK (
                reward_category IN ('service_credit', 'partner_cash')
                AND entry_type IN ('earned', 'reversed', 'manual_credit', 'redeemed', 'restored')
                AND amount_minor > 0
                AND currency ~ '^[A-Z]{3}$'
                AND char_length(trim(reason_type)) > 0
                AND (
                    (
                        entry_type = 'earned'
                        AND referred_client_id IS NOT NULL
                        AND referral_relationship_id IS NOT NULL
                        AND referral_commercial_evidence_id IS NOT NULL
                        AND financial_obligation_id IS NOT NULL
                        AND financial_ledger_entry_id IS NOT NULL
                        AND reward_program_version_id IS NOT NULL
                        AND reverses_entry_id IS NULL
                        AND reason IS NULL
                        AND comment IS NULL
                        AND created_by_user_id IS NULL
                    )
                    OR (
                        entry_type = 'reversed'
                        AND reverses_entry_id IS NOT NULL
                        AND reason IS NOT NULL
                        AND char_length(trim(reason)) > 0
                    )
                    OR (
                        entry_type = 'manual_credit'
                        AND reward_category = 'partner_cash'
                        AND referred_client_id IS NULL
                        AND referral_relationship_id IS NULL
                        AND referral_commercial_evidence_id IS NULL
                        AND financial_obligation_id IS NULL
                        AND financial_ledger_entry_id IS NULL
                        AND reward_program_version_id IS NULL
                        AND reverses_entry_id IS NULL
                        AND reason IS NOT NULL
                        AND char_length(trim(reason)) > 0
                        AND created_by_user_id IS NOT NULL
                    )
                    OR (
                        entry_type = 'redeemed'
                        AND reward_category = 'service_credit'
                        AND referred_client_id IS NULL
                        AND referral_relationship_id IS NULL
                        AND referral_commercial_evidence_id IS NULL
                        AND financial_obligation_id IS NOT NULL
                        AND financial_ledger_entry_id IS NOT NULL
                        AND reward_program_version_id IS NULL
                        AND reverses_entry_id IS NULL
                        AND reason_type = 'credit_redemption'
                        AND reason IS NULL
                        AND comment IS NULL
                        AND created_by_user_id IS NULL
                    )
                    OR (
                        entry_type = 'restored'
                        AND reward_category = 'service_credit'
                        AND referred_client_id IS NULL
                        AND referral_relationship_id IS NULL
                        AND referral_commercial_evidence_id IS NULL
                        AND financial_obligation_id IS NULL
                        AND financial_ledger_entry_id IS NULL
                        AND reward_program_version_id IS NULL
                        AND reverses_entry_id IS NOT NULL
                        AND reason_type = 'credit_redemption_restore'
                        AND reason IS NOT NULL
                        AND char_length(trim(reason)) > 0
                        AND created_by_user_id IS NOT NULL
                    )
                )
            )");
            DB::statement('CREATE TRIGGER ref_reward_ledger_immutable BEFORE UPDATE OR DELETE ON referral_reward_ledger_entries FOR EACH ROW EXECUTE FUNCTION prevent_referral_reward_ledger_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::table('referral_reward_ledger_entries')->whereIn('entry_type', ['redeemed', 'restored'])->exists()) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS ref_reward_ledger_immutable ON referral_reward_ledger_entries');
            DB::statement('ALTER TABLE referral_reward_ledger_entries DROP CONSTRAINT IF EXISTS ref_reward_ledger_shape_check');
            DB::statement("ALTER TABLE referral_reward_ledger_entries ADD CONSTRAINT ref_reward_ledger_shape_check CHECK (
                entry_type IN ('earned', 'reversed', 'manual_credit')
                AND amount_minor > 0
                AND currency ~ '^[A-Z]{3}$'
                AND char_length(trim(reason_type)) > 0
                AND (
                    (entry_type = 'earned' AND reverses_entry_id IS NULL AND reason IS NULL)
                    OR (entry_type = 'reversed' AND reverses_entry_id IS NOT NULL AND reason IS NOT NULL AND char_length(trim(reason)) > 0)
                    OR (entry_type = 'manual_credit' AND reason IS NOT NULL AND char_length(trim(reason)) > 0)
                )
            )");
            DB::statement('CREATE TRIGGER ref_reward_ledger_immutable BEFORE UPDATE OR DELETE ON referral_reward_ledger_entries FOR EACH ROW EXECUTE FUNCTION prevent_referral_reward_ledger_mutation()');
        }

        Schema::table('referral_reward_ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('ref_reward_ledger_org_beneficiary_category_index');
            $table->dropColumn('reward_category');
        });
    }
};
