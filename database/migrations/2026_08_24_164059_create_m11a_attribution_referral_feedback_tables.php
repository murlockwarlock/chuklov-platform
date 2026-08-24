<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
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
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'client_id']);
            $table->index(['organization_id', 'source_type', 'captured_at']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
        });

        Schema::create('pre_auth_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
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
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'session_hash']);
            $table->index(['organization_id', 'expires_at', 'consumed_at']);
            $table->foreign(['organization_id', 'consumed_client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->nullOnDelete();
        });

        Schema::create('client_referral_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('public_code', 128);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'client_id']);
            $table->unique(['organization_id', 'id', 'client_id']);
            $table->unique(['organization_id', 'public_code']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
        });

        Schema::create('referral_relationships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('referral_identity_id');
            $table->foreignId('referrer_client_id');
            $table->foreignId('referred_client_id');
            $table->string('attribution_source_type', 32);
            $table->string('attribution_source', 120)->nullable();
            $table->timestampTz('registered_at')->useCurrent();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'referred_client_id']);
            $table->index(['organization_id', 'referrer_client_id', 'registered_at']);
            $table->foreign(['organization_id', 'referral_identity_id', 'referrer_client_id'])
                ->references(['organization_id', 'id', 'client_id'])
                ->on('client_referral_identities')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'referrer_client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'referred_client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
        });

        Schema::create('referral_conversion_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('referral_relationship_id');
            $table->foreignId('financial_obligation_id');
            $table->foreignId('financial_ledger_entry_id');
            $table->string('finance_status', 32);
            $table->string('observation_source', 40);
            $table->timestampTz('observed_at')->useCurrent();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'referral_relationship_id', 'financial_obligation_id']);
            $table->unique(['organization_id', 'financial_ledger_entry_id']);
            $table->index(['organization_id', 'referral_relationship_id', 'observed_at']);
            $table->foreign(['organization_id', 'referral_relationship_id'])
                ->references(['organization_id', 'id'])
                ->on('referral_relationships')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'financial_obligation_id'])
                ->references(['organization_id', 'id'])
                ->on('financial_obligations')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'financial_ledger_entry_id'])
                ->references(['organization_id', 'id'])
                ->on('financial_ledger_entries')
                ->restrictOnDelete();
        });

        Schema::create('feedback_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedTinyInteger('positive_threshold')->default(8);
            $table->boolean('low_score_feedback_required')->default(true);
            $table->string('review_url_ru', 2048)->nullable();
            $table->string('review_url_en', 2048)->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique('organization_id');
        });

        Schema::create('feedback_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->unsignedTinyInteger('score');
            $table->string('source', 40);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->text('internal_feedback')->nullable();
            $table->timestampTz('submitted_at')->useCurrent();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'client_id', 'idempotency_key']);
            $table->index(['organization_id', 'client_id', 'submitted_at']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE client_attributions ADD CONSTRAINT client_attributions_source_type_check CHECK (source_type IN ('legacy', 'manual', 'source', 'referral', 'utm'))");
            DB::statement("ALTER TABLE pre_auth_attributions ADD CONSTRAINT pre_auth_attributions_source_type_check CHECK (source_type IN ('source', 'referral', 'utm'))");
            DB::statement('ALTER TABLE referral_relationships ADD CONSTRAINT referral_relationships_no_self_check CHECK (referrer_client_id <> referred_client_id)');
            DB::statement("ALTER TABLE referral_conversion_observations ADD CONSTRAINT referral_conversion_observations_status_check CHECK (finance_status = 'settled')");
            DB::statement('ALTER TABLE feedback_configurations ADD CONSTRAINT feedback_configurations_threshold_check CHECK (positive_threshold BETWEEN 1 AND 10)');
            DB::statement('ALTER TABLE feedback_submissions ADD CONSTRAINT feedback_submissions_score_check CHECK (score BETWEEN 1 AND 10)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_submissions');
        Schema::dropIfExists('feedback_configurations');
        Schema::dropIfExists('referral_conversion_observations');
        Schema::dropIfExists('referral_relationships');
        Schema::dropIfExists('client_referral_identities');
        Schema::dropIfExists('pre_auth_attributions');
        Schema::dropIfExists('client_attributions');
    }
};
