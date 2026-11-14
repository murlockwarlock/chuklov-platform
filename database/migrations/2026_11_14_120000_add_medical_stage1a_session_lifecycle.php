<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_sessions', function (Blueprint $table): void {
            $table->string('status', 16)->default('draft')->after('booking_id');
            $table->timestampTz('completed_at')->nullable()->after('status');
            $table->timestampTz('edited_at')->nullable()->after('completed_at');
            $table->text('therapist_notes')->nullable()->after('observations');
            $table->index(['organization_id', 'client_id', 'status', 'occurred_at'], 'medical_sessions_lifecycle_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE medical_sessions ADD CONSTRAINT medical_sessions_status_check CHECK (status IN ('draft', 'completed'))");
        }

        Schema::create('medical_session_protocol_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_session_id');
            $table->unsignedBigInteger('patient_profile_id')->nullable();
            $table->uuid('lineage_key');
            $table->unsignedInteger('step_order')->default(0);
            $table->text('step_text');
            $table->unsignedInteger('encryption_key_version')->default(1);
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'medical_session_id', 'lineage_key'], 'medical_session_protocol_steps_lineage_unique');
            $table->foreign(['organization_id', 'client_id', 'medical_session_id'])
                ->references(['organization_id', 'client_id', 'id'])
                ->on('medical_sessions')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'client_id', 'patient_profile_id'])
                ->references(['organization_id', 'clinical_client_id', 'id'])
                ->on('patient_profiles')
                ->restrictOnDelete();
            $table->index(['organization_id', 'medical_session_id', 'step_order'], 'medical_session_protocol_steps_order_index');
        });
    }

    public function down(): void
    {
        throw new LogicException('Medical session lifecycle and protocol history are retained by forward-only migrations.');
    }
};
