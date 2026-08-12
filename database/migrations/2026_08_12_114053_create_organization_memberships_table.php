<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'administrator', 'staff'])->default('staff');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->index(['organization_id', 'role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_memberships');
    }
};
