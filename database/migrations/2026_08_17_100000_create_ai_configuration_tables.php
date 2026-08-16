<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organization_credentials', 'revision_id')) {
            Schema::table('organization_credentials', function (Blueprint $table): void {
                $table->uuid('revision_id')->nullable()->after('credential_name');
            });

            $credentials = DB::table('organization_credentials')->get();
            foreach ($credentials as $credential) {
                DB::table('organization_credentials')
                    ->where('id', $credential->id)
                    ->update(['revision_id' => (string) Str::uuid()]);
            }

            Schema::table('organization_credentials', function (Blueprint $table): void {
                $table->uuid('revision_id')->nullable(false)->change();
                $table->unique(['organization_id', 'id']);
            });
        }

        Schema::create('ai_organization_safety_controls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('is_ai_globally_enabled')->default(true);
            $table->json('disabled_capabilities');
            $table->json('disabled_providers');
            $table->unsignedInteger('max_tokens_per_run')->default(8192);
            $table->unsignedBigInteger('max_daily_spend_minor_units')->default(5000);
            $table->unsignedInteger('max_runs_per_minute')->default(60);
            $table->unsignedInteger('max_tool_calls_per_run')->default(5);
            $table->unsignedInteger('default_timeout_seconds')->default(60);
            $table->unsignedInteger('max_failover_attempts')->default(3);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('ai_organization_daily_budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->date('usage_date');
            $table->unsignedBigInteger('spent_minor_units')->default(0);
            $table->unsignedBigInteger('reserved_minor_units')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'usage_date']);
        });

        Schema::create('ai_prompts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('key', 80);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('capability', 80);
            $table->foreignId('active_version_id')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'capability']);
        });

        Schema::create('ai_prompt_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('prompt_id');
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->text('system_prompt');
            $table->text('user_prompt_template');
            $table->json('variables_schema');
            $table->json('parameter_config');
            $table->json('context_policy');
            $table->json('output_schema')->nullable();
            $table->json('allowed_tools');
            $table->text('change_notes')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'prompt_id', 'id']);
            $table->unique(['organization_id', 'prompt_id', 'version']);
            $table->foreign(['organization_id', 'prompt_id'])
                ->references(['organization_id', 'id'])->on('ai_prompts')->restrictOnDelete();
            $table->index(['organization_id', 'prompt_id', 'status']);
        });

        Schema::table('ai_prompts', function (Blueprint $table): void {
            $table->foreign(['organization_id', 'id', 'active_version_id'])
                ->references(['organization_id', 'prompt_id', 'id'])
                ->on('ai_prompt_versions')->nullOnDelete();
        });

        Schema::create('ai_provider_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('provider_name', 64);
            $table->string('display_name', 200);
            $table->boolean('is_enabled')->default(true);
            $table->string('health_status', 32)->default('healthy');
            $table->foreignId('credential_id')->nullable();
            $table->json('options');
            $table->timestampTz('last_checked_at')->nullable();
            $table->text('last_health_error')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'provider_name']);
            $table->foreign(['organization_id', 'credential_id'])
                ->references(['organization_id', 'id'])->on('organization_credentials')->nullOnDelete();
        });

        Schema::create('ai_model_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_config_id');
            $table->string('model_name', 120);
            $table->string('display_name', 200);
            $table->boolean('is_enabled')->default(true);
            $table->string('lifecycle_status', 32)->default('active');
            $table->json('capabilities');
            $table->json('pricing_snapshot');
            $table->unsignedInteger('failover_priority')->default(100);
            $table->foreignId('active_release_id')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'provider_config_id', 'id']);
            $table->unique(['organization_id', 'provider_config_id', 'model_name']);
            $table->foreign(['organization_id', 'provider_config_id'])
                ->references(['organization_id', 'id'])->on('ai_provider_configurations')->restrictOnDelete();
            $table->index(['organization_id', 'is_enabled', 'failover_priority']);
        });

        Schema::create('ai_model_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('model_config_id');
            $table->unsignedInteger('release_number');
            $table->string('status', 32)->default('active');
            $table->string('provider_name', 64);
            $table->string('model_name', 120);
            $table->json('capabilities');
            $table->json('pricing_snapshot');
            $table->timestampTz('activated_at')->nullable();
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'model_config_id', 'id']);
            $table->unique(['organization_id', 'model_config_id', 'release_number']);
            $table->foreign(['organization_id', 'model_config_id'])
                ->references(['organization_id', 'id'])->on('ai_model_configurations')->restrictOnDelete();
        });

        Schema::table('ai_model_configurations', function (Blueprint $table): void {
            $table->foreign(['organization_id', 'id', 'active_release_id'])
                ->references(['organization_id', 'model_config_id', 'id'])
                ->on('ai_model_releases')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ai_prompt_versions ADD CONSTRAINT ai_prompt_versions_status_check CHECK (status IN ('draft', 'active', 'retired'))");
            DB::statement("ALTER TABLE ai_provider_configurations ADD CONSTRAINT ai_provider_configurations_health_check CHECK (health_status IN ('healthy', 'degraded', 'unavailable'))");
            DB::statement("ALTER TABLE ai_model_configurations ADD CONSTRAINT ai_model_configurations_lifecycle_check CHECK (lifecycle_status IN ('active', 'preview', 'deprecated'))");
            DB::statement('ALTER TABLE ai_organization_daily_budgets ADD CONSTRAINT ai_daily_budgets_non_negative_check CHECK (spent_minor_units >= 0 AND reserved_minor_units >= 0)');
        }
    }

    public function down(): void
    {
        Schema::table('ai_model_configurations', function (Blueprint $table): void {
            $table->dropForeign(['organization_id', 'id', 'active_release_id']);
        });
        Schema::dropIfExists('ai_model_releases');
        Schema::dropIfExists('ai_model_configurations');
        Schema::dropIfExists('ai_provider_configurations');
        Schema::table('ai_prompts', function (Blueprint $table): void {
            $table->dropForeign(['organization_id', 'id', 'active_version_id']);
        });
        Schema::dropIfExists('ai_prompt_versions');
        Schema::dropIfExists('ai_prompts');
        Schema::dropIfExists('ai_organization_daily_budgets');
        Schema::dropIfExists('ai_organization_safety_controls');

        if (Schema::hasColumn('organization_credentials', 'revision_id')) {
            Schema::table('organization_credentials', function (Blueprint $table): void {
                $table->dropColumn('revision_id');
            });
        }
    }
};
