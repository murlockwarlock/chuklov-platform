<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_taxonomy_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('taxonomy', 64);
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'taxonomy', 'name']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'taxonomy', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Analytics taxonomy groups are retained by forward-only migrations.');
    }
};
