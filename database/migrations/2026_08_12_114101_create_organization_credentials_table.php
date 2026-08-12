<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('provider', 64);
            $table->string('credential_name', 100);
            $table->text('credentials');
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamp('last_rotated_at')->nullable();
            $table->foreignId('rotated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'provider', 'credential_name']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_credentials');
    }
};
