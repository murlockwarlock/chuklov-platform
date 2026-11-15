<?php

use App\Modules\Sessions\Application\MedicalStage1BCatalogPreset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_test_definitions', function (Blueprint $table): void {
            $table->string('test_type', 8)->nullable()->after('section');
            $table->jsonb('catalog_config')->nullable()->after('test_type');
            $table->string('catalog_set', 32)->nullable()->after('catalog_config');
            $table->boolean('default_in_session')->default(false)->after('is_active');
            $table->index(['organization_id', 'catalog_set', 'default_in_session'], 'medical_test_definitions_default_index');
        });

        Schema::table('medical_test_values', function (Blueprint $table): void {
            $table->string('value_type', 32)->nullable()->after('label');
        });

        Schema::table('medical_cause_definitions', function (Blueprint $table): void {
            $table->jsonb('synonyms')->nullable()->after('group_name');
            $table->text('tooltip')->nullable()->after('synonyms');
            $table->string('moderation_status', 32)->default('active')->after('is_active');
            $table->foreignId('merged_into_id')->nullable()->after('moderation_status');
            $table->foreign(['organization_id', 'merged_into_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_cause_definitions')
                ->restrictOnDelete();
            $table->index(['organization_id', 'moderation_status', 'is_active'], 'medical_causes_moderation_index');
        });

        Schema::table('medical_session_test_results', function (Blueprint $table): void {
            $table->text('structured_payload')->nullable()->after('after_value_id');
            $table->unsignedInteger('structured_payload_key_version')->nullable()->after('structured_payload');
            $table->unsignedInteger('before_violation_count')->default(0)->after('structured_payload_key_version');
            $table->unsignedInteger('after_violation_count')->default(0)->after('before_violation_count');
            $table->boolean('review_again')->default(false)->after('after_violation_count');
            $table->index(['organization_id', 'medical_session_id', 'review_again'], 'medical_session_test_review_index');
        });

        Schema::table('medical_session_root_causes', function (Blueprint $table): void {
            $table->boolean('is_primary')->default(false)->after('free_text');
            $table->text('influences')->nullable()->after('is_primary');
        });

        Schema::table('medical_sessions', function (Blueprint $table): void {
            $table->text('recommendation_draft')->nullable()->after('result');
            $table->text('recommendation_generated')->nullable()->after('recommendation_draft');
            $table->unsignedInteger('recommendation_revision')->default(0)->after('recommendation_generated');
            $table->unsignedInteger('recommendation_key_version')->nullable()->after('recommendation_revision');
        });

        Schema::create('medical_test_cause_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('medical_test_definition_id');
            $table->foreignId('medical_cause_definition_id');
            $table->string('scope_key', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'medical_test_definition_id', 'medical_cause_definition_id', 'scope_key'], 'medical_test_cause_links_unique');
            $table->foreign(['organization_id', 'medical_test_definition_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_test_definitions')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'medical_cause_definition_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_cause_definitions')
                ->restrictOnDelete();
            $table->index(['organization_id', 'medical_test_definition_id', 'scope_key', 'is_active'], 'medical_test_cause_links_lookup_index');
        });

        Schema::create('medical_client_safety_markers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('marker_key', 80);
            $table->text('marker_text');
            $table->unsignedInteger('encryption_key_version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('source_session_id')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable();
            $table->timestampTz('cleared_at')->nullable();
            $table->foreignId('cleared_by')->nullable();
            $table->text('clear_reason')->nullable();
            $table->unsignedInteger('clear_reason_key_version')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'source_session_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_sessions')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'activated_by'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'cleared_by'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'is_active'], 'medical_client_safety_marker_lookup_index');
        });
        DB::statement('CREATE UNIQUE INDEX medical_client_safety_marker_active_unique ON medical_client_safety_markers (organization_id, client_id, marker_key) WHERE is_active = true');

        Schema::create('medical_session_safety_markers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_session_id');
            $table->string('marker_key', 80);
            $table->text('marker_text');
            $table->unsignedInteger('encryption_key_version')->default(1);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'medical_session_id', 'marker_key'], 'medical_session_safety_marker_unique');
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'medical_session_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_sessions')
                ->cascadeOnDelete();
        });

        Schema::create('medical_session_recommendation_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_session_id');
            $table->unsignedInteger('recommendation_revision');
            $table->string('channel', 32);
            $table->string('status', 32)->default('claimed');
            $table->string('idempotency_key', 180);
            $table->string('content_hash', 64);
            $table->string('provider_reference', 255)->nullable();
            $table->string('error_code', 120)->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'idempotency_key'], 'medical_recommendation_delivery_idempotency_unique');
            $table->unique(['organization_id', 'medical_session_id', 'recommendation_revision', 'channel'], 'medical_recommendation_delivery_revision_unique');
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'medical_session_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_sessions')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'client_id', 'created_at'], 'medical_recommendation_delivery_history_index');
        });

        $this->seedExistingOrganizations();
    }

    public function down(): void
    {
        throw new LogicException('Medical Stage 1B history is preserved by forward-only migrations.');
    }

    private function seedExistingOrganizations(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        $now = now();
        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            foreach (MedicalStage1BCatalogPreset::definitions() as $definition) {
                $definitionId = DB::table('medical_test_definitions')
                    ->where('organization_id', $organizationId)
                    ->where('key', $definition['key'])
                    ->value('id');
                if ($definitionId === null) {
                    $definitionId = DB::table('medical_test_definitions')->insertGetId([
                        'organization_id' => $organizationId,
                        'key' => $definition['key'],
                        'name' => $definition['name'],
                        'section' => $definition['section'],
                        'test_type' => $definition['type'],
                        'catalog_config' => json_encode($definition['configuration'], JSON_THROW_ON_ERROR),
                        'catalog_set' => $definition['set'],
                        'is_active' => true,
                        'default_in_session' => $definition['is_default'],
                        'sort_order' => $definition['sort_order'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                foreach ($definition['values'] as $value) {
                    DB::table('medical_test_values')->updateOrInsert(
                        ['organization_id' => $organizationId, 'medical_test_definition_id' => $definitionId, 'key' => $value['key']],
                        [...$value, 'organization_id' => $organizationId, 'medical_test_definition_id' => $definitionId, 'created_at' => $now, 'updated_at' => $now],
                    );
                }
            }
        }
    }
};
