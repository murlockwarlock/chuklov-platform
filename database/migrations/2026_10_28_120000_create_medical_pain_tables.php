<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_profiles', function (Blueprint $table): void {
            $table->unique(['organization_id', 'client_id', 'id'], 'medical_profiles_org_client_id_unique');
        });

        Schema::create('medical_frequency_period_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('key', 40);
            $table->string('label', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'is_active', 'sort_order']);
        });

        Schema::create('medical_profile_pains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_profile_id');
            $table->unsignedBigInteger('patient_profile_id')->nullable();
            $table->uuid('lineage_key');
            $table->text('name');
            $table->string('frequency_mode', 16)->default('permanent');
            $table->unsignedInteger('frequency_count')->nullable();
            $table->string('frequency_period_unit', 40)->nullable();
            $table->json('time_of_day');
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('encryption_key_version')->default(1);
            $table->timestampsTz();

            $table->unique(['organization_id', 'medical_profile_id', 'lineage_key'], 'medical_profile_pains_lineage_unique');
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'client_id', 'medical_profile_id'])
                ->references(['organization_id', 'client_id', 'id'])
                ->on('medical_profiles')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'client_id', 'patient_profile_id'])
                ->references(['organization_id', 'clinical_client_id', 'id'])
                ->on('patient_profiles')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'sort_order']);
        });

        Schema::create('medical_session_pains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_session_id');
            $table->unsignedBigInteger('patient_profile_id')->nullable();
            $table->uuid('lineage_key');
            $table->text('name');
            $table->string('frequency_mode', 16)->default('permanent');
            $table->unsignedInteger('frequency_count')->nullable();
            $table->string('frequency_period_unit', 40)->nullable();
            $table->json('time_of_day');
            $table->unsignedSmallInteger('before_score')->nullable();
            $table->unsignedSmallInteger('after_score')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('encryption_key_version')->default(1);
            $table->timestampsTz();

            $table->unique(['organization_id', 'medical_session_id', 'lineage_key'], 'medical_session_pains_lineage_unique');
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'client_id', 'medical_session_id'])
                ->references(['organization_id', 'client_id', 'id'])
                ->on('medical_sessions')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'client_id', 'patient_profile_id'])
                ->references(['organization_id', 'clinical_client_id', 'id'])
                ->on('patient_profiles')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'medical_session_id', 'sort_order'], 'medical_session_pains_order_index');
            $table->index(['organization_id', 'client_id', 'lineage_key']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE medical_profile_pains ADD CONSTRAINT medical_profile_pains_frequency_check CHECK ((frequency_mode = 'permanent' AND frequency_count IS NULL AND frequency_period_unit IS NULL) OR (frequency_mode = 'count' AND frequency_count IS NOT NULL AND frequency_count > 0 AND frequency_period_unit IS NOT NULL))");
            DB::statement('ALTER TABLE medical_profile_pains ADD CONSTRAINT medical_profile_pains_score_check CHECK (score IS NULL OR score BETWEEN 0 AND 10)');
            DB::statement("ALTER TABLE medical_session_pains ADD CONSTRAINT medical_session_pains_frequency_check CHECK ((frequency_mode = 'permanent' AND frequency_count IS NULL AND frequency_period_unit IS NULL) OR (frequency_mode = 'count' AND frequency_count IS NOT NULL AND frequency_count > 0 AND frequency_period_unit IS NOT NULL))");
            DB::statement('ALTER TABLE medical_session_pains ADD CONSTRAINT medical_session_pains_before_score_check CHECK (before_score IS NULL OR before_score BETWEEN 0 AND 10)');
            DB::statement('ALTER TABLE medical_session_pains ADD CONSTRAINT medical_session_pains_after_score_check CHECK (after_score IS NULL OR after_score BETWEEN 0 AND 10)');
        }
    }

    public function down(): void
    {
        throw new LogicException('Medical pain history is retained by forward-only migrations.');
    }
};
