<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('patient_profile_id');
            $table->unsignedBigInteger('grantor_client_id');
            $table->unsignedBigInteger('grantee_client_id');
            $table->unsignedBigInteger('legal_document_id');
            $table->string('legal_document_version', 64);
            $table->string('scope', 64);
            $table->uuid('request_key');
            $table->timestampTz('granted_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'request_key']);
            $table->index(['organization_id', 'patient_profile_id', 'grantee_client_id', 'scope', 'revoked_at'], 'patient_access_grants_lookup_index');
            $table->foreign(['organization_id', 'patient_profile_id'], 'patient_access_grants_patient_fk')
                ->references(['organization_id', 'id'])->on('patient_profiles')->restrictOnDelete();
            $table->foreign(['organization_id', 'grantor_client_id'], 'patient_access_grants_grantor_fk')
                ->references(['organization_id', 'id'])->on('clients')->restrictOnDelete();
            $table->foreign(['organization_id', 'grantee_client_id'], 'patient_access_grants_grantee_fk')
                ->references(['organization_id', 'id'])->on('clients')->restrictOnDelete();
            $table->foreign(['organization_id', 'legal_document_id'], 'patient_access_grants_legal_document_fk')
                ->references(['organization_id', 'id'])->on('legal_documents')->restrictOnDelete();
            $table->foreign('revoked_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE patient_access_grants ADD CONSTRAINT patient_access_grants_scope_check CHECK (scope IN ('medical_records'))");
            DB::statement('ALTER TABLE patient_access_grants ADD CONSTRAINT patient_access_grants_distinct_clients_check CHECK (grantor_client_id <> grantee_client_id)');
        }
    }

    public function down(): void
    {
        throw new LogicException('Patient medical access history is retained by forward-only migrations.');
    }
};
