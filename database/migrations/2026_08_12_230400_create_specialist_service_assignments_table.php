<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialist_service_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('specialist_id');
            $table->foreignId('service_id');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(
                ['organization_id', 'specialist_id', 'service_id'],
                'specialist_service_assignments_pair_unique',
            );
            $table->foreign(['organization_id', 'specialist_id'])
                ->references(['organization_id', 'id'])
                ->on('specialists')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'service_id'])
                ->references(['organization_id', 'id'])
                ->on('services')
                ->cascadeOnDelete();
            $table->index(
                ['organization_id', 'service_id', 'specialist_id'],
                'specialist_service_assignments_service_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specialist_service_assignments');
    }
};
