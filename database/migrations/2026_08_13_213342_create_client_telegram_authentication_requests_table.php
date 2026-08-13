<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_telegram_authentication_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable();
            $table->foreignId('client_channel_identity_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('browser_session_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestamps();
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->index(['organization_id', 'browser_session_hash', 'consumed_at'], 'client_telegram_auth_browser_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_telegram_authentication_requests');
    }
};
