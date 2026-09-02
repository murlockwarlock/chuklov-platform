<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_review_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'feedback_review_destinations_org_fk')
                ->restrictOnDelete();
            $table->string('label', 160);
            $table->string('url', 2048);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'feedback_review_destinations_org_id_unique');
            $table->index(['organization_id', 'is_active', 'sort_order'], 'feedback_review_destinations_org_active_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_review_destinations');
    }
};
