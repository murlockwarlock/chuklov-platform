<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_profiles', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id'], 'medical_profiles_org_id_unique');
        });

        Schema::create('medical_profile_history_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_profile_id');
            $table->uuid('lineage_key');
            $table->string('entry_type', 20);
            $table->unsignedSmallInteger('year');
            $table->text('description');
            $table->unsignedInteger('encryption_key_version')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'medical_profile_id', 'entry_type', 'lineage_key'], 'medical_profile_history_lineage_unique');
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'medical_profile_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_profiles')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'entry_type', 'year']);

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->check("entry_type IN ('injury', 'operation')", 'medical_profile_history_entry_type_check');
                $table->check('year BETWEEN 1800 AND 2200', 'medical_profile_history_year_check');
            }
        });
    }

    public function down(): void
    {
        throw new LogicException('Medical profile history is preserved by forward-only migrations.');
    }
};
