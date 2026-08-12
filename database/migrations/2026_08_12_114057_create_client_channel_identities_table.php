<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_channel_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('channel', 32);
            $table->string('external_id', 191);
            $table->enum('verification_status', ['unverified', 'verified', 'revoked'])->default('unverified');
            $table->string('verification_method', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
            $table->unique(['organization_id', 'channel', 'external_id']);
            $table->index(['organization_id', 'client_id']);
            $table->index(['organization_id', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_channel_identities');
    }
};
