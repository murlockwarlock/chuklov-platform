<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('owner_client_id');
            $table->unsignedBigInteger('clinical_client_id');
            $table->unsignedBigInteger('linked_account_client_id')->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('middle_name', 100)->nullable();
            $table->string('relationship', 20);
            $table->string('gender', 10)->nullable();
            $table->date('birth_date')->nullable();
            $table->uuid('creation_key')->nullable();
            $table->char('creation_hash', 64)->nullable();
            $table->timestampTz('owner_revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'clinical_client_id']);
            $table->unique(['organization_id', 'clinical_client_id', 'id'], 'patients_clinical_identity_unique');
            $table->unique(['organization_id', 'owner_client_id', 'id'], 'patients_owner_identity_unique');
            $table->unique(['organization_id', 'owner_client_id', 'creation_key'], 'patients_creation_unique');
            $table->index(['organization_id', 'owner_client_id', 'owner_revoked_at']);
            $table->index(['organization_id', 'linked_account_client_id']);
            foreach (['owner_client_id', 'clinical_client_id', 'linked_account_client_id'] as $column) {
                $table->foreign(['organization_id', $column], 'patients_'.$column.'_fk')
                    ->references(['organization_id', 'id'])->on('clients')->restrictOnDelete();
            }
        });
        Schema::create('patient_invitations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('owner_client_id');
            $table->unsignedBigInteger('patient_profile_id');
            $table->uuid('request_key');
            $table->char('token_hash', 64);
            $table->text('encrypted_token');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->unsignedBigInteger('intended_client_id')->nullable();
            $table->unsignedBigInteger('accepted_by_client_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'token_hash']);
            $table->unique(['organization_id', 'owner_client_id', 'request_key'], 'patient_invitation_request_unique');
            $table->index(['organization_id', 'patient_profile_id', 'revoked_at']);
            $table->foreign(['organization_id', 'owner_client_id', 'patient_profile_id'], 'patient_invitation_owner_fk')
                ->references(['organization_id', 'owner_client_id', 'id'])->on('patient_profiles')->restrictOnDelete();
            $table->foreign(['organization_id', 'accepted_by_client_id'], 'patient_invitation_account_fk')
                ->references(['organization_id', 'id'])->on('clients')->restrictOnDelete();
            $table->foreign(['organization_id', 'intended_client_id'], 'patient_invitation_intended_account_fk')
                ->references(['organization_id', 'id'])->on('clients')->restrictOnDelete();
        });
        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('patient_profile_id')->nullable();
            $table->foreign(['organization_id', 'patient_profile_id'], 'booking_patient_tenant_fk')
                ->references(['organization_id', 'id'])->on('patient_profiles')->restrictOnDelete();
            $table->index(['organization_id', 'patient_profile_id', 'starts_at']);
        });
        foreach (['medical_profiles', 'medical_sessions'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->unsignedBigInteger('patient_profile_id')->nullable();
                $table->foreign(['organization_id', 'client_id', 'patient_profile_id'], $name.'_patient_identity_fk')
                    ->references(['organization_id', 'clinical_client_id', 'id'])->on('patient_profiles')->restrictOnDelete();
                $table->index(['organization_id', 'patient_profile_id']);
            });
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE patient_profiles ADD CONSTRAINT patient_profile_details_check CHECK (relationship IN ('SELF','SPOUSE','CHILD','PARENT') AND (gender IS NULL OR gender IN ('MALE','FEMALE')) AND (relationship = 'SELF' OR (first_name IS NOT NULL AND last_name IS NOT NULL AND gender IS NOT NULL AND birth_date IS NOT NULL)) AND (relationship <> 'SELF' OR (owner_client_id = clinical_client_id AND linked_account_client_id IS NOT NULL AND linked_account_client_id = clinical_client_id)))");
            DB::statement('ALTER TABLE patient_invitations ADD CONSTRAINT patient_invitation_acceptance_check CHECK (((accepted_at IS NULL) = (accepted_by_client_id IS NULL)) AND (accepted_by_client_id IS NULL OR (intended_client_id IS NOT NULL AND accepted_by_client_id = intended_client_id)))');
        }
    }

    public function down(): void
    {
        throw new LogicException('Patient identities and medical associations are retained by forward-only migrations.');
    }
};
