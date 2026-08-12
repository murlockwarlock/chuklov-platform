<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('full_name', 160);
            $table->string('email', 320)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('language', 10)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->string('lead_source', 120)->nullable();
            $table->string('referral_code', 160)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'email']);
            $table->index(['organization_id', 'phone']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
