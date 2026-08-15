<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->text('anamnesis')->nullable();
            $table->text('complaints_goals')->nullable();
            $table->text('operations_injuries')->nullable();
            $table->text('medicines')->nullable();
            $table->text('supplements')->nullable();
            $table->unsignedInteger('encryption_key_version')->default(1);
            $table->timestampsTz();

            $table->unique(['organization_id', 'client_id']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->index(['organization_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_profiles');
    }
};
