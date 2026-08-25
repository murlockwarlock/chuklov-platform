<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_telegram_authentication_requests', function (Blueprint $table): void {
            $table->unique(
                ['organization_id', 'id'],
                'client_telegram_auth_requests_org_id_id_unique',
            );
        });

        Schema::table('financial_obligations', function (Blueprint $table): void {
            $table->unique(
                ['organization_id', 'id', 'client_id'],
                'financial_obligations_org_id_id_client_unique',
            );
        });

        Schema::table('financial_ledger_entries', function (Blueprint $table): void {
            $table->unique(
                ['organization_id', 'id', 'obligation_id'],
                'financial_ledger_entries_org_id_id_obligation_unique',
            );
        });

        Schema::create('client_acquisition_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'client_acq_reg_org_fk')
                ->restrictOnDelete();
            $table->foreignId('client_id');
            $table->char('session_hash', 64)->nullable();
            $table->foreignId('telegram_authentication_request_id')->nullable();
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'client_acq_reg_org_id_unique');
            $table->unique(['organization_id', 'client_id'], 'client_acq_reg_org_client_unique');
            $table->unique(['organization_id', 'session_hash'], 'client_acq_reg_org_session_unique');
            $table->unique(
                ['organization_id', 'telegram_authentication_request_id'],
                'client_acq_reg_org_tg_auth_unique',
            );
            $table->foreign(['organization_id', 'client_id'], 'client_acq_reg_org_client_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'telegram_authentication_request_id'],
                'client_acq_reg_org_tg_auth_fk',
            )
                ->references(['organization_id', 'id'])
                ->on('client_telegram_authentication_requests')
                ->restrictOnDelete();
        });

        Schema::create('client_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'client_attr_org_fk')
                ->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('source_type', 32);
            $table->string('source', 120)->nullable();
            $table->string('referral_code', 128)->nullable();
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 160)->nullable();
            $table->string('utm_content', 160)->nullable();
            $table->string('utm_term', 160)->nullable();
            $table->string('capture_channel', 40);
            $table->string('capture_context', 80)->nullable();
            $table->timestampTz('captured_at')->useCurrent();
            $table->timestampTz('accepted_at')->useCurrent();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'client_attr_org_id_unique');
            $table->unique(['organization_id', 'client_id'], 'client_attr_org_client_unique');
            $table->index(
                ['organization_id', 'source_type', 'captured_at'],
                'client_attr_org_source_captured_index',
            );
            $table->foreign(['organization_id', 'client_id'], 'client_attr_org_client_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
        });

        Schema::create('pre_auth_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'pre_auth_attr_org_fk')
                ->restrictOnDelete();
            $table->char('session_hash', 64);
            $table->string('source_type', 32);
            $table->string('source', 120)->nullable();
            $table->string('referral_code', 128)->nullable();
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 160)->nullable();
            $table->string('utm_content', 160)->nullable();
            $table->string('utm_term', 160)->nullable();
            $table->string('capture_channel', 40);
            $table->string('capture_context', 80)->nullable();
            $table->timestampTz('captured_at')->useCurrent();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->foreignId('consumed_client_id')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'pre_auth_attr_org_id_unique');
            $table->unique(['organization_id', 'session_hash'], 'pre_auth_attr_org_session_unique');
            $table->index(
                ['organization_id', 'expires_at', 'consumed_at'],
                'pre_auth_attr_org_expiry_consume_index',
            );
            $table->foreign(
                ['organization_id', 'consumed_client_id'],
                'pre_auth_attr_org_client_fk',
            )
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
        });

        Schema::create('client_referral_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'ref_identity_org_fk')
                ->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('public_code', 128);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'ref_identity_org_id_unique');
            $table->unique(['organization_id', 'client_id'], 'ref_identity_org_client_unique');
            $table->unique(
                ['organization_id', 'id', 'client_id'],
                'ref_identity_org_id_client_unique',
            );
            $table->unique(['organization_id', 'public_code'], 'ref_identity_org_code_unique');
            $table->foreign(['organization_id', 'client_id'], 'ref_identity_org_client_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
        });

        Schema::create('referral_relationships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'ref_rel_org_fk')
                ->restrictOnDelete();
            $table->foreignId('referrer_client_id');
            $table->foreignId('referred_client_id');
            $table->string('establishment_method', 32);
            $table->timestampTz('registered_at')->useCurrent();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'ref_rel_org_id_unique');
            $table->unique(
                ['organization_id', 'referred_client_id'],
                'ref_rel_org_referred_unique',
            );
            $table->unique(
                ['organization_id', 'id', 'referred_client_id'],
                'referral_relationships_org_id_id_referred_unique',
            );
            $table->index(
                ['organization_id', 'referrer_client_id', 'registered_at'],
                'ref_rel_org_referrer_registered_index',
            );
            $table->foreign(
                ['organization_id', 'referrer_client_id'],
                'ref_rel_org_referrer_fk',
            )
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'referred_client_id'],
                'ref_rel_org_referred_fk',
            )
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
        });

        Schema::create('integration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'integration_events_org_fk')
                ->restrictOnDelete();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 120);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('idempotency_key', 191);
            $table->json('payload');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('occurred_at');
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('processing_started_at')->nullable();
            $table->char('processing_token', 64)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'integration_events_org_id_unique');
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'integration_events_org_idempotency_unique',
            );
            $table->index(
                ['status', 'available_at'],
                'integration_events_status_available_index',
            );
            $table->index(
                ['organization_id', 'event_type', 'status', 'available_at'],
                'integration_events_org_type_status_available_index',
            );
        });

        Schema::create('referral_commercial_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'ref_evidence_org_fk')
                ->restrictOnDelete();
            $table->foreignId('integration_event_id');
            $table->foreignId('referral_relationship_id')->nullable();
            $table->foreignId('referred_client_id');
            $table->foreignId('financial_obligation_id');
            $table->foreignId('financial_ledger_entry_id');
            $table->string('evidence_type', 64);
            $table->string('observation_source', 40);
            $table->timestampTz('observed_at')->useCurrent();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'ref_evidence_org_id_unique');
            $table->unique(
                ['organization_id', 'integration_event_id'],
                'ref_evidence_org_event_unique',
            );
            $table->unique(
                ['organization_id', 'financial_obligation_id'],
                'ref_evidence_org_obligation_unique',
            );
            $table->unique(
                ['organization_id', 'financial_ledger_entry_id'],
                'ref_evidence_org_ledger_unique',
            );
            $table->index(
                ['organization_id', 'referred_client_id', 'observed_at'],
                'ref_evidence_org_referred_observed_index',
            );
            $table->foreign(
                ['organization_id', 'integration_event_id'],
                'ref_evidence_org_event_fk',
            )
                ->references(['organization_id', 'id'])
                ->on('integration_events')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'referral_relationship_id'],
                'ref_evidence_org_relationship_fk',
            )
                ->references(['organization_id', 'id'])
                ->on('referral_relationships')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'referral_relationship_id', 'referred_client_id'],
                'ref_evidence_org_relationship_client_fk',
            )
                ->references(['organization_id', 'id', 'referred_client_id'])
                ->on('referral_relationships')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'referred_client_id'],
                'ref_evidence_org_client_fk',
            )
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'financial_obligation_id', 'referred_client_id'],
                'ref_evidence_org_obligation_client_fk',
            )
                ->references(['organization_id', 'id', 'client_id'])
                ->on('financial_obligations')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'financial_ledger_entry_id', 'financial_obligation_id'],
                'ref_evidence_org_ledger_obligation_fk',
            )
                ->references(['organization_id', 'id', 'obligation_id'])
                ->on('financial_ledger_entries')
                ->restrictOnDelete();
        });

        Schema::create('feedback_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'feedback_config_org_fk')
                ->restrictOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedTinyInteger('positive_threshold')->default(8);
            $table->boolean('low_score_feedback_required')->default(true);
            $table->string('review_url_ru', 2048)->nullable();
            $table->string('review_url_en', 2048)->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'feedback_config_org_id_unique');
            $table->unique('organization_id', 'feedback_config_org_unique');
        });

        Schema::create('feedback_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'feedback_submissions_org_fk')
                ->restrictOnDelete();
            $table->foreignId('client_id');
            $table->unsignedTinyInteger('score');
            $table->string('source', 40);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->text('internal_feedback')->nullable();
            $table->timestampTz('submitted_at')->useCurrent();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'feedback_submissions_org_id_unique');
            $table->unique(
                ['organization_id', 'client_id', 'idempotency_key'],
                'feedback_submissions_org_client_key_unique',
            );
            $table->index(
                ['organization_id', 'client_id', 'submitted_at'],
                'feedback_submissions_org_client_submitted_index',
            );
            $table->foreign(
                ['organization_id', 'client_id'],
                'feedback_submissions_org_client_fk',
            )
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE client_acquisition_registrations ADD CONSTRAINT client_acquisition_registrations_source_check CHECK (session_hash IS NOT NULL OR telegram_authentication_request_id IS NOT NULL)');
            DB::statement("ALTER TABLE client_attributions ADD CONSTRAINT client_attributions_source_type_check CHECK (source_type IN ('legacy', 'manual', 'source', 'referral', 'utm'))");
            DB::statement("ALTER TABLE pre_auth_attributions ADD CONSTRAINT pre_auth_attributions_source_type_check CHECK (source_type IN ('source', 'referral', 'utm'))");
            DB::statement("ALTER TABLE referral_relationships ADD CONSTRAINT referral_relationships_method_check CHECK (establishment_method IN ('automatic_referral_link', 'manual_crm'))");
            DB::statement('ALTER TABLE referral_relationships ADD CONSTRAINT referral_relationships_no_self_check CHECK (referrer_client_id <> referred_client_id)');
            DB::statement("ALTER TABLE integration_events ADD CONSTRAINT integration_events_type_check CHECK (event_type = 'finance.obligation.settled')");
            DB::statement("ALTER TABLE integration_events ADD CONSTRAINT integration_events_status_check CHECK (status IN ('pending', 'processing', 'retryable', 'processed', 'failed'))");
            DB::statement("ALTER TABLE referral_commercial_evidence ADD CONSTRAINT referral_commercial_evidence_type_check CHECK (evidence_type = 'finance_obligation_settled')");
            DB::statement("ALTER TABLE referral_commercial_evidence ADD CONSTRAINT referral_commercial_evidence_source_check CHECK (observation_source = 'finance')");
            DB::statement('ALTER TABLE feedback_configurations ADD CONSTRAINT feedback_configurations_threshold_check CHECK (positive_threshold BETWEEN 1 AND 10)');
            DB::statement('ALTER TABLE feedback_submissions ADD CONSTRAINT feedback_submissions_score_check CHECK (score BETWEEN 1 AND 10)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_submissions');
        Schema::dropIfExists('feedback_configurations');
        Schema::dropIfExists('referral_commercial_evidence');
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('referral_relationships');
        Schema::dropIfExists('client_referral_identities');
        Schema::dropIfExists('pre_auth_attributions');
        Schema::dropIfExists('client_attributions');
        Schema::dropIfExists('client_acquisition_registrations');

        Schema::table('financial_ledger_entries', function (Blueprint $table): void {
            $table->dropUnique('financial_ledger_entries_org_id_id_obligation_unique');
        });
        Schema::table('financial_obligations', function (Blueprint $table): void {
            $table->dropUnique('financial_obligations_org_id_id_client_unique');
        });
        Schema::table('client_telegram_authentication_requests', function (Blueprint $table): void {
            $table->dropUnique('client_telegram_auth_requests_org_id_id_unique');
        });
    }
};
