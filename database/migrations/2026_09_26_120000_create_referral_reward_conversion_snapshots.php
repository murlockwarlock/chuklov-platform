<?php

use App\Modules\Referrals\Application\EnsureReferralRewardConversionSnapshots;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_reward_conversion_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'ref_reward_conversion_org_fk')
                ->restrictOnDelete();
            $table->foreignId('referral_reward_ledger_entry_id');
            $table->string('purpose', 48);
            $table->bigInteger('source_amount_minor');
            $table->char('source_currency', 3);
            $table->bigInteger('target_amount_minor');
            $table->char('target_currency', 3);
            $table->decimal('rate', 38, 18);
            $table->foreignId('rate_id')->nullable()->constrained('organization_exchange_rates')->nullOnDelete();
            $table->unsignedInteger('rate_version')->nullable();
            $table->timestampTz('effective_at')->nullable();
            $table->string('rounding_mode', 32);
            $table->unsignedTinyInteger('source_scale');
            $table->unsignedTinyInteger('target_scale');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(
                ['organization_id', 'referral_reward_ledger_entry_id'],
                'ref_reward_conversion_org_entry_unique',
            );
            $table->index(
                ['organization_id', 'target_currency', 'referral_reward_ledger_entry_id'],
                'ref_reward_conversion_org_target_index',
            );
            $table->foreign(
                ['organization_id', 'referral_reward_ledger_entry_id'],
                'ref_reward_conversion_org_entry_fk',
            )
                ->references(['organization_id', 'id'])
                ->on('referral_reward_ledger_entries')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE referral_reward_conversion_snapshots ADD CONSTRAINT ref_reward_conversion_shape_check CHECK (
                purpose IN ('service_credit_earning', 'legacy_service_credit_earning', 'legacy_service_credit_normalization', 'service_credit_reversal')
                AND source_amount_minor > 0
                AND target_amount_minor > 0
                AND source_currency ~ '^[A-Z]{3}$'
                AND target_currency ~ '^[A-Z]{3}$'
                AND rate > 0
                AND rounding_mode IN ('down', 'half_even', 'half_up')
                AND source_scale BETWEEN 0 AND 4
                AND target_scale BETWEEN 0 AND 4
            )");
            DB::statement('CREATE OR REPLACE FUNCTION prevent_referral_reward_conversion_snapshot_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION \'referral reward conversion snapshots are immutable\'; END; $$ LANGUAGE plpgsql');
            DB::statement('CREATE TRIGGER ref_reward_conversion_snapshot_immutable BEFORE UPDATE OR DELETE ON referral_reward_conversion_snapshots FOR EACH ROW EXECUTE FUNCTION prevent_referral_reward_conversion_snapshot_mutation()');
        }

        app(EnsureReferralRewardConversionSnapshots::class)->handle();
    }

    public function down(): void
    {
        if (! Schema::hasTable('referral_reward_conversion_snapshots')
            || DB::table('referral_reward_conversion_snapshots')->exists()) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS ref_reward_conversion_snapshot_immutable ON referral_reward_conversion_snapshots');
            DB::statement('DROP FUNCTION IF EXISTS prevent_referral_reward_conversion_snapshot_mutation()');
        }

        Schema::dropIfExists('referral_reward_conversion_snapshots');
    }
};
