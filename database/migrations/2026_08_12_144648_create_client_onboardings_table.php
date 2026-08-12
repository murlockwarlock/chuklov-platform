<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_onboardings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('flow_version', 32);
            $table->enum('current_stage', ['contacts', 'profile', 'service', 'goals'])->default('contacts');
            $table->json('data');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_id', 'flow_version']);
            $table->index(['organization_id', 'current_stage']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_onboardings');
    }
};
