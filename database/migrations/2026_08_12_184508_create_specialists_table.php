<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('display_name', 160);
            $table->boolean('is_active')->default(true);
            $table->foreignId('staff_user_id')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'staff_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->restrictOnDelete();
            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'staff_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specialists');
    }
};
