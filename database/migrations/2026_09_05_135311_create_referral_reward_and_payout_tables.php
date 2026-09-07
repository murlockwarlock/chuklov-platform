<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_reward_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'ref_reward_program_org_fk')
                ->restrictOnDelete();
            $table->foreignId('current_version_id')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'ref_reward_program_org_id_unique');
            $table->unique('organization_id', 'ref_reward_program_org_unique');
        });

        Schema::create('referral_reward_program_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'ref_reward_version_org_fk')
                ->restrictOnDelete();
            $table->foreignId('program_id');
            $table->unsignedInteger('version');
            $table->boolean('enabled')->default(false);
            $table->string('qualification_rule', 40)->nullable();
            $table->string('formula', 40)->nullable();
            $table->bigInteger('fixed_amount_minor')->nullable();
            $table->char('fixed_currency', 3)->nullable();
            $table->unsignedInteger('percentage_basis_points')->nullable();
            $table->string('rounding_mode', 32)->nullable();
            $table->timestampTz('effective_at');
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'id'], 'ref_reward_version_org_id_unique');
            $table->unique(['organization_id', 'program_id', 'id'], 'ref_reward_version_org_program_id_unique');
            $table->unique(['organization_id', 'program_id', 'version'], 'ref_reward_version_org_program_version_unique');
            $table->index(['organization_id', 'program_id', 'effective_at'], 'ref_reward_version_org_program_effective_index');
            $table->foreign(['organization_id', 'program_id'], 'ref_reward_version_org_program_fk')
                ->references(['organization_id', 'id'])
                ->on('referral_reward_programs')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by_user_id'], 'ref_reward_version_org_creator_fk')
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
        });

        Schema::table('referral_reward_programs', function (Blueprint $table): void {
            $table->foreign(['organization_id', 'id', 'current_version_id'], 'ref_reward_program_current_version_fk')
                ->references(['organization_id', 'program_id', 'id'])
                ->on('referral_reward_program_versions')
                ->restrictOnDelete();
        });

        Schema::create('referral_reward_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'ref_reward_ledger_org_fk')
                ->restrictOnDelete();
            $table->foreignId('beneficiary_client_id');
            $table->foreignId('referred_client_id');
            $table->foreignId('referral_relationship_id');
            $table->foreignId('referral_commercial_evidence_id');
            $table->foreignId('financial_obligation_id');
            $table->foreignId('financial_ledger_entry_id');
            $table->foreignId('reward_program_version_id');
            $table->string('entry_type', 24);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason_type', 48);
            $table->text('reason')->nullable();
            $table->foreignId('reverses_entry_id')->nullable();
            $table->string('idempotency_key', 191);
            $table->char('request_hash', 64);
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'id'], 'ref_reward_ledger_org_id_unique');
            $table->unique(['organization_id', 'idempotency_key'], 'ref_reward_ledger_org_key_unique');
            $table->unique(['organization_id', 'referral_commercial_evidence_id', 'entry_type'], 'ref_reward_ledger_org_evidence_type_unique');
            $table->unique(['organization_id', 'reverses_entry_id'], 'ref_reward_ledger_org_reversal_unique');
            $table->index(['organization_id', 'beneficiary_client_id', 'currency', 'occurred_at'], 'ref_reward_ledger_org_beneficiary_currency_index');
            $table->foreign(['organization_id', 'beneficiary_client_id'], 'ref_reward_ledger_org_beneficiary_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'referred_client_id'], 'ref_reward_ledger_org_referred_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'referral_relationship_id', 'referred_client_id'], 'ref_reward_ledger_org_relationship_fk')
                ->references(['organization_id', 'id', 'referred_client_id'])
                ->on('referral_relationships')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'referral_commercial_evidence_id'], 'ref_reward_ledger_org_evidence_fk')
                ->references(['organization_id', 'id'])
                ->on('referral_commercial_evidence')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'financial_obligation_id', 'referred_client_id'], 'ref_reward_ledger_org_obligation_fk')
                ->references(['organization_id', 'id', 'client_id'])
                ->on('financial_obligations')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'financial_ledger_entry_id', 'financial_obligation_id'], 'ref_reward_ledger_org_finance_entry_fk')
                ->references(['organization_id', 'id', 'obligation_id'])
                ->on('financial_ledger_entries')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'reward_program_version_id'], 'ref_reward_ledger_org_version_fk')
                ->references(['organization_id', 'id'])
                ->on('referral_reward_program_versions')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'reverses_entry_id'], 'ref_reward_ledger_org_reverses_fk')
                ->references(['organization_id', 'id'])
                ->on('referral_reward_ledger_entries')
                ->restrictOnDelete();
        });

        Schema::create('referral_payout_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'ref_payout_org_fk')
                ->restrictOnDelete();
            $table->foreignId('beneficiary_client_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24)->default('requested');
            $table->string('idempotency_key', 191);
            $table->char('request_hash', 64);
            $table->timestampTz('requested_at');
            $table->foreignId('approved_by_user_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->text('payment_note')->nullable();
            $table->string('payment_reference', 180)->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'ref_payout_org_id_unique');
            $table->unique(['organization_id', 'idempotency_key'], 'ref_payout_org_key_unique');
            $table->index(['organization_id', 'status', 'requested_at'], 'ref_payout_org_status_requested_index');
            $table->index(['organization_id', 'beneficiary_client_id', 'currency', 'status'], 'ref_payout_org_beneficiary_status_index');
            $table->foreign(['organization_id', 'beneficiary_client_id'], 'ref_payout_org_beneficiary_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            foreach (['approved_by_user_id', 'rejected_by_user_id', 'cancelled_by_user_id', 'paid_by_user_id'] as $column) {
                $table->foreign(['organization_id', $column], 'ref_payout_org_'.str_replace('_by_user_id', '_by_user_fk', $column))
                    ->references(['organization_id', 'user_id'])
                    ->on('organization_memberships')
                    ->nullOnDelete();
            }
        });

        Schema::create('referral_payout_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'ref_payout_event_org_fk')
                ->restrictOnDelete();
            $table->foreignId('payout_request_id');
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('actor_user_id')->nullable();
            $table->string('actor_type', 24);
            $table->text('reason')->nullable();
            $table->text('payment_note')->nullable();
            $table->string('payment_reference', 180)->nullable();
            $table->string('idempotency_key', 191);
            $table->char('request_hash', 64);
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'id'], 'ref_payout_event_org_id_unique');
            $table->unique(['organization_id', 'idempotency_key'], 'ref_payout_event_org_key_unique');
            $table->index(['organization_id', 'payout_request_id', 'occurred_at'], 'ref_payout_event_org_request_occurred_index');
            $table->foreign(['organization_id', 'payout_request_id'], 'ref_payout_event_org_request_fk')
                ->references(['organization_id', 'id'])
                ->on('referral_payout_requests')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'actor_user_id'], 'ref_payout_event_org_actor_fk')
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE referral_reward_program_versions ADD CONSTRAINT ref_reward_version_shape_check CHECK (version > 0 AND ((enabled = false AND qualification_rule IS NULL AND formula IS NULL AND fixed_amount_minor IS NULL AND fixed_currency IS NULL AND percentage_basis_points IS NULL AND rounding_mode IS NULL) OR (enabled = true AND qualification_rule IN ('first_settled_payment', 'every_settled_payment') AND formula IN ('fixed_amount', 'percentage_of_settlement') AND rounding_mode IN ('down', 'half_even', 'half_up') AND ((formula = 'fixed_amount' AND fixed_amount_minor IS NOT NULL AND fixed_amount_minor > 0 AND fixed_currency IS NOT NULL AND fixed_currency ~ '^[A-Z]{3}$' AND percentage_basis_points IS NULL) OR (formula = 'percentage_of_settlement' AND fixed_amount_minor IS NULL AND fixed_currency IS NULL AND percentage_basis_points IS NOT NULL AND percentage_basis_points BETWEEN 1 AND 10000)))))");
            DB::statement("ALTER TABLE referral_reward_program_versions ADD CONSTRAINT ref_reward_version_rule_check CHECK (NOT enabled OR (qualification_rule IS NOT NULL AND formula IS NOT NULL AND rounding_mode IS NOT NULL AND qualification_rule IN ('first_settled_payment', 'every_settled_payment') AND formula IN ('fixed_amount', 'percentage_of_settlement') AND rounding_mode IN ('down', 'half_even', 'half_up')))");
            DB::statement("ALTER TABLE referral_reward_ledger_entries ADD CONSTRAINT ref_reward_ledger_shape_check CHECK (entry_type IN ('earned', 'reversed') AND amount_minor > 0 AND currency ~ '^[A-Z]{3}$' AND char_length(trim(reason_type)) > 0 AND ((entry_type = 'earned' AND reverses_entry_id IS NULL AND reason IS NULL) OR (entry_type = 'reversed' AND reverses_entry_id IS NOT NULL AND reason IS NOT NULL AND char_length(trim(reason)) > 0)) )");
            DB::statement("ALTER TABLE referral_payout_requests ADD CONSTRAINT ref_payout_request_shape_check CHECK (amount_minor > 0 AND currency ~ '^[A-Z]{3}$' AND status IN ('requested', 'approved', 'paid', 'rejected', 'cancelled') AND ((status = 'requested' AND approved_by_user_id IS NULL AND approved_at IS NULL AND rejected_by_user_id IS NULL AND rejected_at IS NULL AND rejection_reason IS NULL AND cancelled_by_user_id IS NULL AND cancelled_at IS NULL AND paid_by_user_id IS NULL AND paid_at IS NULL AND payment_note IS NULL AND payment_reference IS NULL) OR (status = 'approved' AND approved_by_user_id IS NOT NULL AND approved_at IS NOT NULL AND rejected_by_user_id IS NULL AND rejected_at IS NULL AND rejection_reason IS NULL AND cancelled_by_user_id IS NULL AND cancelled_at IS NULL AND paid_by_user_id IS NULL AND paid_at IS NULL AND payment_note IS NULL AND payment_reference IS NULL) OR (status = 'paid' AND approved_by_user_id IS NOT NULL AND approved_at IS NOT NULL AND paid_by_user_id IS NOT NULL AND paid_at IS NOT NULL AND rejected_by_user_id IS NULL AND rejected_at IS NULL AND rejection_reason IS NULL AND cancelled_by_user_id IS NULL AND cancelled_at IS NULL) OR (status = 'rejected' AND rejected_by_user_id IS NOT NULL AND rejected_at IS NOT NULL AND rejection_reason IS NOT NULL AND char_length(trim(rejection_reason)) > 0 AND cancelled_by_user_id IS NULL AND cancelled_at IS NULL AND paid_by_user_id IS NULL AND paid_at IS NULL AND payment_note IS NULL AND payment_reference IS NULL) OR (status = 'cancelled' AND approved_by_user_id IS NULL AND approved_at IS NULL AND rejected_by_user_id IS NULL AND rejected_at IS NULL AND rejection_reason IS NULL AND cancelled_at IS NOT NULL AND paid_by_user_id IS NULL AND paid_at IS NULL AND payment_note IS NULL AND payment_reference IS NULL)))");
            DB::statement("ALTER TABLE referral_payout_request_events ADD CONSTRAINT ref_payout_event_shape_check CHECK (actor_type IN ('client', 'user') AND ((actor_type = 'user' AND actor_user_id IS NOT NULL) OR (actor_type = 'client' AND actor_user_id IS NULL)) AND ((to_status = 'requested' AND from_status IS NULL AND actor_type = 'client') OR (to_status = 'approved' AND from_status = 'requested') OR (to_status = 'paid' AND from_status = 'approved') OR (to_status = 'rejected' AND from_status IN ('requested', 'approved')) OR (to_status = 'cancelled' AND from_status = 'requested')) AND to_status IN ('requested', 'approved', 'paid', 'rejected', 'cancelled') AND (to_status IN ('requested', 'cancelled') OR actor_type = 'user') AND (to_status <> 'rejected' OR (reason IS NOT NULL AND char_length(trim(reason)) > 0)) AND (to_status <> 'paid' OR actor_type = 'user') AND (to_status = 'paid' OR (payment_note IS NULL AND payment_reference IS NULL)))");
            DB::statement('CREATE OR REPLACE FUNCTION prevent_referral_reward_ledger_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION \'referral reward ledger entries are append-only\'; END; $$ LANGUAGE plpgsql');
            DB::statement('CREATE TRIGGER ref_reward_ledger_immutable BEFORE UPDATE OR DELETE ON referral_reward_ledger_entries FOR EACH ROW EXECUTE FUNCTION prevent_referral_reward_ledger_mutation()');
            DB::statement('CREATE OR REPLACE FUNCTION prevent_referral_reward_version_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION \'referral reward program versions are immutable\'; END; $$ LANGUAGE plpgsql');
            DB::statement('CREATE TRIGGER ref_reward_version_immutable BEFORE UPDATE OR DELETE ON referral_reward_program_versions FOR EACH ROW EXECUTE FUNCTION prevent_referral_reward_version_mutation()');
            DB::statement('CREATE OR REPLACE FUNCTION prevent_referral_payout_event_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION \'referral payout request events are append-only\'; END; $$ LANGUAGE plpgsql');
            DB::statement('CREATE TRIGGER ref_payout_event_immutable BEFORE UPDATE OR DELETE ON referral_payout_request_events FOR EACH ROW EXECUTE FUNCTION prevent_referral_payout_event_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS ref_payout_event_immutable ON referral_payout_request_events');
            DB::statement('DROP FUNCTION IF EXISTS prevent_referral_payout_event_mutation()');
            DB::statement('DROP TRIGGER IF EXISTS ref_reward_version_immutable ON referral_reward_program_versions');
            DB::statement('DROP FUNCTION IF EXISTS prevent_referral_reward_version_mutation()');
            DB::statement('DROP TRIGGER IF EXISTS ref_reward_ledger_immutable ON referral_reward_ledger_entries');
            DB::statement('DROP FUNCTION IF EXISTS prevent_referral_reward_ledger_mutation()');
        }

        Schema::dropIfExists('referral_payout_request_events');
        Schema::dropIfExists('referral_payout_requests');
        Schema::dropIfExists('referral_reward_ledger_entries');
        Schema::table('referral_reward_programs', function (Blueprint $table): void {
            $table->dropForeign('ref_reward_program_current_version_fk');
        });
        Schema::dropIfExists('referral_reward_program_versions');
        Schema::dropIfExists('referral_reward_programs');
    }
};
