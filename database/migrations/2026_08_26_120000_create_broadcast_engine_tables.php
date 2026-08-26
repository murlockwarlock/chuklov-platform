<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notification_templates DROP CONSTRAINT notification_templates_purpose_check');
            DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_purpose_check CHECK (purpose IN ('service', 'transactional', 'marketing'))");
        }
        Schema::create('broadcast_client_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations', 'id', 'bc_profile_org_fk')->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('b2b_role', 80)->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'bc_profile_org_id_uq');
            $table->unique(['organization_id', 'client_id'], 'bc_profile_org_client_uq');
            $table->foreign(['organization_id', 'client_id'], 'bc_profile_org_client_fk')->references(['organization_id', 'id'])->on('clients')->cascadeOnDelete();
            $table->index(['organization_id', 'b2b_role'], 'bc_profile_org_role_ix');
        });

        Schema::create('broadcast_client_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations', 'id', 'bc_tag_org_fk')->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('tag', 80);
            $table->timestampsTz();
            $table->unique(['organization_id', 'client_id', 'tag'], 'bc_tag_org_client_tag_uq');
            $table->foreign(['organization_id', 'client_id'], 'bc_tag_org_client_fk')->references(['organization_id', 'id'])->on('clients')->cascadeOnDelete();
            $table->index(['organization_id', 'tag', 'client_id'], 'bc_tag_org_tag_client_ix');
        });

        Schema::create('broadcast_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations', 'id', 'bc_campaign_org_fk')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', 'id', 'bc_campaign_creator_fk')->nullOnDelete();
            $table->string('name', 160);
            $table->string('state', 24)->default('draft');
            $table->string('send_mode', 16)->default('immediate');
            $table->jsonb('channel_priority');
            $table->jsonb('segment_definition');
            $table->string('segment_summary', 500);
            $table->foreignId('template_version_ru_id')->nullable();
            $table->foreignId('template_version_en_id')->nullable();
            $table->unsignedInteger('draft_version')->default(1);
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('dispatch_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedBigInteger('audience_count')->default(0);
            $table->unsignedBigInteger('sent_count')->default(0);
            $table->unsignedBigInteger('delivered_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->unsignedBigInteger('suppressed_count')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'bc_campaign_org_id_uq');
            $table->foreign(['organization_id', 'template_version_ru_id'], 'bc_campaign_ru_template_fk')->references(['organization_id', 'id'])->on('notification_template_versions')->restrictOnDelete();
            $table->foreign(['organization_id', 'template_version_en_id'], 'bc_campaign_en_template_fk')->references(['organization_id', 'id'])->on('notification_template_versions')->restrictOnDelete();
            $table->index(['organization_id', 'state', 'scheduled_at'], 'bc_campaign_due_ix');
        });

        Schema::create('broadcast_audience_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations', 'id', 'bc_snapshot_org_fk')->restrictOnDelete();
            $table->foreignId('campaign_id');
            $table->unsignedInteger('version');
            $table->jsonb('segment_definition');
            $table->string('segment_summary', 500);
            $table->jsonb('channel_priority');
            $table->foreignId('template_version_ru_id')->nullable();
            $table->foreignId('template_version_en_id')->nullable();
            $table->unsignedBigInteger('matched_count')->default(0);
            $table->unsignedBigInteger('eligible_count')->default(0);
            $table->unsignedBigInteger('suppressed_count')->default(0);
            $table->timestampTz('materialized_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'bc_snapshot_org_id_uq');
            $table->unique(['organization_id', 'campaign_id', 'id'], 'bc_snapshot_org_campaign_id_uq');
            $table->unique(['organization_id', 'campaign_id', 'version'], 'bc_snapshot_org_campaign_ver_uq');
            $table->foreign(['organization_id', 'campaign_id'], 'bc_snapshot_org_campaign_fk')->references(['organization_id', 'id'])->on('broadcast_campaigns')->restrictOnDelete();
            $table->foreign(['organization_id', 'template_version_ru_id'], 'bc_snapshot_ru_template_fk')->references(['organization_id', 'id'])->on('notification_template_versions')->restrictOnDelete();
            $table->foreign(['organization_id', 'template_version_en_id'], 'bc_snapshot_en_template_fk')->references(['organization_id', 'id'])->on('notification_template_versions')->restrictOnDelete();
        });

        Schema::table('broadcast_campaigns', function (Blueprint $table): void {
            $table->foreignId('audience_snapshot_id')->nullable();
            $table->foreign(['organization_id', 'id', 'audience_snapshot_id'], 'bc_campaign_org_snapshot_fk')->references(['organization_id', 'campaign_id', 'id'])->on('broadcast_audience_snapshots')->restrictOnDelete();
        });

        Schema::create('broadcast_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations', 'id', 'bc_batch_org_fk')->restrictOnDelete();
            $table->foreignId('campaign_id');
            $table->foreignId('snapshot_id');
            $table->unsignedInteger('sequence');
            $table->string('state', 20)->default('pending');
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'bc_batch_org_id_uq');
            $table->unique(['organization_id', 'campaign_id', 'snapshot_id', 'id'], 'bc_batch_scope_id_uq');
            $table->unique(['organization_id', 'campaign_id', 'sequence'], 'bc_batch_org_campaign_seq_uq');
            $table->foreign(['organization_id', 'campaign_id'], 'bc_batch_org_campaign_fk')->references(['organization_id', 'id'])->on('broadcast_campaigns')->restrictOnDelete();
            $table->foreign(['organization_id', 'campaign_id', 'snapshot_id'], 'bc_batch_org_snapshot_fk')->references(['organization_id', 'campaign_id', 'id'])->on('broadcast_audience_snapshots')->restrictOnDelete();
            $table->index(['organization_id', 'state', 'id'], 'bc_batch_claim_ix');
        });

        Schema::create('broadcast_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations', 'id', 'bc_recipient_org_fk')->restrictOnDelete();
            $table->foreignId('campaign_id');
            $table->foreignId('snapshot_id');
            $table->foreignId('batch_id')->nullable();
            $table->foreignId('client_id');
            $table->string('kind', 16)->default('production');
            $table->string('language', 12);
            $table->string('channel', 40)->nullable();
            $table->string('external_id', 255)->nullable();
            $table->jsonb('render_context');
            $table->string('state', 24)->default('pending');
            $table->string('exclusion_code', 64)->nullable();
            $table->char('idempotency_key', 64);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'bc_recipient_org_id_uq');
            $table->unique(['organization_id', 'campaign_id', 'client_id', 'kind', 'snapshot_id'], 'bc_recipient_logical_uq');
            $table->unique(['organization_id', 'idempotency_key'], 'bc_recipient_idem_uq');
            $table->foreign(['organization_id', 'campaign_id'], 'bc_recipient_org_campaign_fk')->references(['organization_id', 'id'])->on('broadcast_campaigns')->restrictOnDelete();
            $table->foreign(['organization_id', 'campaign_id', 'snapshot_id'], 'bc_recipient_org_snapshot_fk')->references(['organization_id', 'campaign_id', 'id'])->on('broadcast_audience_snapshots')->restrictOnDelete();
            $table->foreign(['organization_id', 'campaign_id', 'snapshot_id', 'batch_id'], 'bc_recipient_org_batch_fk')->references(['organization_id', 'campaign_id', 'snapshot_id', 'id'])->on('broadcast_batches')->restrictOnDelete();
            $table->foreign(['organization_id', 'client_id'], 'bc_recipient_org_client_fk')->references(['organization_id', 'id'])->on('clients')->restrictOnDelete();
            $table->index(['organization_id', 'batch_id', 'state', 'id'], 'bc_recipient_batch_claim_ix');
        });

        Schema::create('broadcast_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations', 'id', 'bc_attempt_org_fk')->restrictOnDelete();
            $table->foreignId('recipient_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->string('outcome', 32);
            $table->string('error_code', 64)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->timestampTz('attempted_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'recipient_id', 'attempt_number'], 'bc_attempt_org_recipient_no_uq');
            $table->foreign(['organization_id', 'recipient_id'], 'bc_attempt_org_recipient_fk')->references(['organization_id', 'id'])->on('broadcast_recipients')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE broadcast_campaigns ADD CONSTRAINT bc_campaign_state_ck CHECK (state IN ('draft','scheduled','dispatching','completed','cancelled'))");
            DB::statement("ALTER TABLE broadcast_campaigns ADD CONSTRAINT bc_campaign_mode_ck CHECK (send_mode IN ('immediate','scheduled'))");
            DB::statement("ALTER TABLE broadcast_recipients ADD CONSTRAINT bc_recipient_kind_ck CHECK (kind IN ('production','test'))");
            DB::statement("ALTER TABLE broadcast_recipients ADD CONSTRAINT bc_recipient_state_ck CHECK (state IN ('pending','suppressed','claimed','delivered','failed'))");
            DB::statement("ALTER TABLE broadcast_batches ADD CONSTRAINT bc_batch_state_ck CHECK (state IN ('pending','claimed','completed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_delivery_attempts');
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcast_batches');
        Schema::table('broadcast_campaigns', function (Blueprint $table): void {
            if (DB::getDriverName() === 'pgsql') {
                $table->dropForeign('bc_campaign_org_snapshot_fk');
            } else {
                $table->dropForeign(['organization_id', 'id', 'audience_snapshot_id']);
            }
            $table->dropColumn('audience_snapshot_id');
        });
        Schema::dropIfExists('broadcast_audience_snapshots');
        Schema::dropIfExists('broadcast_campaigns');
        Schema::dropIfExists('broadcast_client_tags');
        Schema::dropIfExists('broadcast_client_profiles');
        DB::table('notification_template_versions')->whereIn('template_id', DB::table('notification_templates')->select('id')->where('purpose', 'marketing'))->delete();
        DB::table('notification_templates')->where('purpose', 'marketing')->delete();
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notification_templates DROP CONSTRAINT notification_templates_purpose_check');
            DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_purpose_check CHECK (purpose IN ('service', 'transactional'))");
        }
    }
};
