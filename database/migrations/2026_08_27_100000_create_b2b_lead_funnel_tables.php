<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcast_client_profiles', function (Blueprint $table): void {
            $table->string('b2b_specialist_answer', 16)->nullable();
            $table->index(
                ['organization_id', 'b2b_specialist_answer'],
                'b2b_profile_org_answer_ix',
            );
        });

        Schema::create('b2b_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'b2b_leads_org_fk')
                ->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('b2b_specialist_answer', 16);
            $table->string('source_channel', 24);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('status', 32)->default('new');
            $table->unsignedInteger('event_version')->default(1);
            $table->timestampTz('submitted_at')->useCurrent();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'b2b_leads_org_id_uq');
            $table->unique(['organization_id', 'id', 'client_id'], 'b2b_leads_org_id_client_uq');
            $table->unique(['organization_id', 'idempotency_key'], 'b2b_leads_org_idem_uq');
            $table->foreign(['organization_id', 'client_id'], 'b2b_leads_org_client_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->index(
                ['organization_id', 'status', 'submitted_at', 'id'],
                'b2b_leads_status_submitted_ix',
            );
            $table->index(
                ['organization_id', 'client_id', 'submitted_at'],
                'b2b_leads_client_submitted_ix',
            );
        });

        Schema::create('b2b_sales_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'b2b_calls_org_fk')
                ->restrictOnDelete();
            $table->foreignId('lead_id');
            $table->foreignId('client_id');
            $table->foreignId('specialist_id');
            $table->string('status', 24)->default('scheduled');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('schedule_timezone', 64);
            $table->string('requested_timezone', 64);
            $table->string('meeting_mode', 16)->default('automatic');
            $table->string('provider_name', 32)->nullable();
            $table->string('provider_meeting_id', 128)->nullable();
            $table->string('provider_meeting_uuid', 191)->nullable();
            $table->string('provider_join_url', 2000)->nullable();
            $table->string('manual_meeting_url', 2000)->nullable();
            $table->string('provider_sync_status', 40)->default('pending');
            $table->string('provider_operation', 24)->nullable();
            $table->unsignedInteger('provider_sync_version')->default(1);
            $table->timestampTz('provider_synced_at')->nullable();
            $table->string('provider_error_code', 120)->nullable();
            $table->string('provider_recreate_meeting_id', 128)->nullable();
            $table->unsignedInteger('event_version')->default(1);
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'b2b_calls_org_id_uq');
            $table->unique(['organization_id', 'id', 'specialist_id'], 'b2b_calls_org_id_specialist_uq');
            $table->unique(['organization_id', 'lead_id'], 'b2b_calls_org_lead_uq');
            $table->foreign(['organization_id', 'lead_id', 'client_id'], 'b2b_calls_org_lead_client_fk')
                ->references(['organization_id', 'id', 'client_id'])
                ->on('b2b_leads')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'client_id'], 'b2b_calls_org_client_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'specialist_id'], 'b2b_calls_org_specialist_fk')
                ->references(['organization_id', 'id'])
                ->on('specialists')
                ->restrictOnDelete();
            $table->index(
                ['organization_id', 'status', 'starts_at', 'id'],
                'b2b_calls_status_starts_ix',
            );
            $table->index(
                ['organization_id', 'provider_sync_status', 'starts_at'],
                'b2b_calls_provider_sync_ix',
            );
        });

        Schema::table('unavailable_periods', function (Blueprint $table): void {
            $table->unsignedBigInteger('b2b_sales_call_id')->nullable();
            $table->unique(
                ['organization_id', 'b2b_sales_call_id'],
                'b2b_occupancy_org_call_uq',
            );
            $table->foreign(
                ['organization_id', 'b2b_sales_call_id', 'specialist_id'],
                'b2b_occupancy_org_call_fk',
            )
                ->references(['organization_id', 'id', 'specialist_id'])
                ->on('b2b_sales_calls')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "ALTER TABLE broadcast_client_profiles ADD CONSTRAINT b2b_profile_answer_ck CHECK (b2b_specialist_answer IS NULL OR b2b_specialist_answer IN ('yes', 'no'))",
        );
        DB::statement(
            "ALTER TABLE b2b_leads ADD CONSTRAINT b2b_leads_state_ck CHECK (b2b_specialist_answer = 'yes' AND source_channel IN ('portal', 'telegram', 'crm') AND status IN ('new', 'contacted', 'zoom_scheduled', 'closed'))",
        );
        DB::statement(
            "ALTER TABLE b2b_sales_calls ADD CONSTRAINT b2b_calls_state_ck CHECK (status IN ('scheduled', 'cancelled') AND meeting_mode IN ('automatic', 'manual') AND (provider_name IS NULL OR provider_name = 'zoom') AND provider_sync_status IN ('not_required', 'pending', 'ready', 'failed', 'cancellation_pending', 'reconciliation_required') AND (provider_operation IS NULL OR provider_operation IN ('create', 'update', 'cancel', 'reconcile', 'recreate')) AND starts_at < ends_at)",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE b2b_sales_calls DROP CONSTRAINT IF EXISTS b2b_calls_state_ck');
            DB::statement('ALTER TABLE b2b_leads DROP CONSTRAINT IF EXISTS b2b_leads_state_ck');
            DB::statement('ALTER TABLE broadcast_client_profiles DROP CONSTRAINT IF EXISTS b2b_profile_answer_ck');
        }

        Schema::table('unavailable_periods', function (Blueprint $table): void {
            $table->dropForeign('b2b_occupancy_org_call_fk');
            $table->dropUnique('b2b_occupancy_org_call_uq');
            $table->dropColumn('b2b_sales_call_id');
        });
        Schema::dropIfExists('b2b_sales_calls');
        Schema::dropIfExists('b2b_leads');
        Schema::table('broadcast_client_profiles', function (Blueprint $table): void {
            $table->dropIndex('b2b_profile_org_answer_ix');
            $table->dropColumn('b2b_specialist_answer');
        });
    }
};
