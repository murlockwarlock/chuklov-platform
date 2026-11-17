<?php

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Sessions\Application\MedicalVitalsSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_sessions', function (Blueprint $table): void {
            $table->text('measurements')->nullable()->after('visual_posture_diagnostics');
        });

        Schema::create('medical_anthropometric_measurements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('measured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('payload');
            $table->unsignedInteger('encryption_key_version')->default(1);
            $table->timestampTz('measured_at');
            $table->timestampsTz();

            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'measured_at', 'id'], 'medical_anthro_client_history_index');
        });

        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            DB::table('organization_settings')->updateOrInsert(
                [
                    'organization_id' => $organizationId,
                    'setting_key' => OrganizationSettingKey::MedicalVitalsThresholds->value,
                ],
                [
                    'value_type' => 'string',
                    'string_value' => json_encode(MedicalVitalsSettings::sourceExamples(), JSON_THROW_ON_ERROR),
                    'integer_value' => null,
                    'boolean_value' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        throw new LogicException('The medical Stage 1C measurement migration is forward-only.');
    }
};
