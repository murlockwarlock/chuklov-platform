<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_reward_program_versions', function (Blueprint $table): void {
            $table->foreignId('partner_profile_id')->nullable()->after('program_id');
            $table->index(
                ['organization_id', 'partner_profile_id', 'effective_at'],
                'ref_reward_version_org_partner_effective_index',
            );
            $table->foreign(
                ['organization_id', 'partner_profile_id'],
                'ref_reward_version_org_partner_fk',
            )
                ->references(['organization_id', 'id'])
                ->on('referral_partner_profiles')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE referral_reward_ledger_entries DROP CONSTRAINT IF EXISTS ref_reward_ledger_shape_check');
        }

        Schema::table('referral_reward_ledger_entries', function (Blueprint $table): void {
            foreach ([
                'referred_client_id',
                'referral_relationship_id',
                'referral_commercial_evidence_id',
                'financial_obligation_id',
                'financial_ledger_entry_id',
                'reward_program_version_id',
            ] as $column) {
                $table->foreignId($column)->nullable()->change();
            }
            $table->text('comment')->nullable()->after('reason');
            $table->foreignId('created_by_user_id')->nullable()->after('comment');
            $table->foreign(
                ['organization_id', 'created_by_user_id'],
                'ref_reward_ledger_org_creator_fk',
            )
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE referral_reward_ledger_entries ADD CONSTRAINT ref_reward_ledger_shape_check CHECK (
                entry_type IN ('earned', 'reversed', 'manual_credit')
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
                )
            )");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE referral_reward_ledger_entries DROP CONSTRAINT IF EXISTS ref_reward_ledger_shape_check');
        }

        Schema::table('referral_reward_ledger_entries', function (Blueprint $table): void {
            $table->dropForeign('ref_reward_ledger_org_creator_fk');
            $table->dropColumn(['comment', 'created_by_user_id']);
            foreach ([
                'referred_client_id',
                'referral_relationship_id',
                'referral_commercial_evidence_id',
                'financial_obligation_id',
                'financial_ledger_entry_id',
                'reward_program_version_id',
            ] as $column) {
                $table->foreignId($column)->nullable(false)->change();
            }
        });

        Schema::table('referral_reward_program_versions', function (Blueprint $table): void {
            $table->dropForeign('ref_reward_version_org_partner_fk');
            $table->dropIndex('ref_reward_version_org_partner_effective_index');
            $table->dropColumn('partner_profile_id');
        });
    }
};
