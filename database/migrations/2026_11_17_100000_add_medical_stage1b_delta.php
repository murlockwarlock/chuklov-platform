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
        Schema::table('medical_session_root_causes', function (Blueprint $table): void {
            $table->string('status', 24)->default('новая')->after('is_primary');
            $table->text('region')->nullable()->after('status');
            $table->unsignedInteger('region_key_version')->nullable()->after('region');
        });
        Schema::table('medical_session_test_results', function (Blueprint $table): void {
            $table->boolean('is_priority')->default(false)->after('review_again');
            $table->text('priority_regions')->nullable()->after('is_priority');
            $table->unsignedInteger('priority_regions_key_version')->nullable()->after('priority_regions');
            $table->boolean('is_stabilized')->default(false)->after('priority_regions_key_version');
            $table->boolean('is_complex_case')->default(false)->after('is_stabilized');
            $table->text('new_cause_ids')->nullable()->after('is_complex_case');
            $table->unsignedInteger('new_cause_ids_key_version')->nullable()->after('new_cause_ids');
            $table->index(['organization_id', 'client_id', 'is_priority'], 'medical_session_test_priority_index');
            $table->index(['organization_id', 'client_id', 'is_stabilized'], 'medical_session_test_stabilized_index');
            $table->index(['organization_id', 'client_id', 'is_complex_case'], 'medical_session_test_complex_index');
        });

        Schema::create('medical_client_root_causes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_cause_definition_id')->nullable();
            $table->string('identity_key', 96);
            $table->string('status', 24)->default('новая');
            $table->text('region')->nullable();
            $table->unsignedInteger('region_key_version')->nullable();
            $table->text('free_text')->nullable();
            $table->unsignedInteger('free_text_key_version')->nullable();
            $table->text('relations')->nullable();
            $table->unsignedInteger('relations_key_version')->nullable();
            $table->foreignId('source_session_id')->nullable();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'client_id', 'identity_key'], 'medical_client_root_cause_identity_unique');
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'medical_cause_definition_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_cause_definitions')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'source_session_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_sessions')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'status'], 'medical_client_root_cause_status_index');
        });

        Schema::create('medical_client_root_cause_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_client_root_cause_id');
            $table->foreignId('medical_session_id')->nullable();
            $table->string('status', 24);
            $table->text('region')->nullable();
            $table->unsignedInteger('region_key_version')->nullable();
            $table->text('free_text')->nullable();
            $table->unsignedInteger('free_text_key_version')->nullable();
            $table->text('relations')->nullable();
            $table->unsignedInteger('relations_key_version')->nullable();
            $table->foreignId('changed_by')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'medical_client_root_cause_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_client_root_causes')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'medical_session_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_sessions')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'changed_by'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->restrictOnDelete();
            $table->unique(['organization_id', 'medical_client_root_cause_id', 'medical_session_id'], 'medical_client_root_cause_history_session_unique');
            $table->index(['organization_id', 'client_id', 'created_at'], 'medical_client_root_cause_history_index');
        });

        $this->seedDeltaCatalog();
    }

    public function down(): void
    {
        throw new LogicException('Medical Stage 1B history is preserved by forward-only migrations.');
    }

    private function seedDeltaCatalog(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        $definition = MedicalStage1BCatalogPreset::definitions()[31] ?? null;
        if (! is_array($definition)) {
            return;
        }
        $now = now();
        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
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
                    'default_in_session' => false,
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

            $energyDefinition = DB::table('medical_test_definitions')
                ->where('organization_id', $organizationId)
                ->where('key', 'medical_test_031')
                ->first(['id', 'catalog_config']);
            if ($energyDefinition !== null) {
                $configuration = is_string($energyDefinition->catalog_config)
                    ? json_decode($energyDefinition->catalog_config, true)
                    : $energyDefinition->catalog_config;
                if (is_array($configuration)) {
                    $rules = is_array($configuration['rules'] ?? null) ? $configuration['rules'] : [];
                    if (($rules['normal_tor_direction'] ?? null) === null) {
                        $rules['normal_tor_direction'] = 'R';
                        $configuration['rules'] = $rules;
                        DB::table('medical_test_definitions')->where('id', $energyDefinition->id)->update([
                            'catalog_config' => json_encode($configuration, JSON_THROW_ON_ERROR),
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        }
    }
};
