<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 100);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'feature_key']);
            $table->index(['organization_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_feature_flags');
    }
};
