<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->foreignId('current_version_id')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active', 'is_visible']);
        });

        Schema::create('tracker_plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('tracker_plan_id');
            $table->unsignedInteger('version');
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->unsignedInteger('duration_days');
            $table->text('description')->nullable();
            $table->boolean('included_access')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->nullable();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'tracker_plan_id', 'version']);
            $table->foreign(['organization_id', 'tracker_plan_id'])
                ->references(['organization_id', 'id'])
                ->on('tracker_plans')
                ->restrictOnDelete();
            $table->index(['organization_id', 'display_order']);
        });

        Schema::table('tracker_plans', function (Blueprint $table): void {
            $table->foreign(['organization_id', 'current_version_id'])
                ->references(['organization_id', 'id'])
                ->on('tracker_plan_versions')
                ->restrictOnDelete();
        });

        Schema::create('tracker_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('tracker_plan_id')->nullable();
            $table->foreignId('tracker_plan_version_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('source', 64);
            $table->text('reason');
            $table->string('applied_plan_name', 160)->nullable();
            $table->bigInteger('applied_price_minor')->nullable();
            $table->char('applied_currency', 3)->nullable();
            $table->unsignedInteger('applied_duration_days')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'tracker_plan_id'])
                ->references(['organization_id', 'id'])
                ->on('tracker_plans')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'tracker_plan_version_id'])
                ->references(['organization_id', 'id'])
                ->on('tracker_plan_versions')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'active']);
        });

        Schema::create('tracker_check_ins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->timestampTz('occurred_at');
            $table->text('note');
            $table->string('source', 32)->default('portal');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'occurred_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tracker_plan_versions ADD CONSTRAINT tracker_plan_versions_price_check CHECK (price_minor >= 0)');
            DB::statement("ALTER TABLE tracker_plan_versions ADD CONSTRAINT tracker_plan_versions_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE tracker_plan_versions ADD CONSTRAINT tracker_plan_versions_duration_check CHECK (duration_days > 0)');
            DB::statement('ALTER TABLE tracker_entitlements ADD CONSTRAINT tracker_entitlements_period_check CHECK (ends_at > starts_at)');
            DB::statement("ALTER TABLE tracker_entitlements ADD CONSTRAINT tracker_entitlements_terms_check CHECK ((applied_price_minor IS NULL AND applied_currency IS NULL AND applied_duration_days IS NULL) OR (applied_price_minor IS NOT NULL AND applied_currency IS NOT NULL AND applied_duration_days IS NOT NULL AND applied_price_minor >= 0 AND applied_currency ~ '^[A-Z]{3}$' AND applied_duration_days > 0))");
            DB::statement('CREATE UNIQUE INDEX tracker_entitlements_one_active ON tracker_entitlements (organization_id, client_id) WHERE active = true');
        } else {
            $table = Schema::getConnection()->getTablePrefix();
            DB::statement('CREATE UNIQUE INDEX tracker_entitlements_one_active ON '.$table.'tracker_entitlements (organization_id, client_id) WHERE active = 1');
        }

        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            DB::table('organization_settings')->insertOrIgnore([
                [
                    'organization_id' => $organizationId,
                    'setting_key' => 'tracker_free_mode',
                    'value_type' => 'boolean',
                    'boolean_value' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'organization_id' => $organizationId,
                    'setting_key' => 'tracker_enabled',
                    'value_type' => 'boolean',
                    'boolean_value' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_check_ins');
        Schema::dropIfExists('tracker_entitlements');
        Schema::table('tracker_plans', function (Blueprint $table): void {
            $table->dropForeign(['organization_id', 'current_version_id']);
        });
        Schema::dropIfExists('tracker_plan_versions');
        Schema::dropIfExists('tracker_plans');
    }
};
