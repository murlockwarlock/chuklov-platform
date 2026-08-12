<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_channel_link_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('channel', 32);
            $table->string('flow', 64);
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
            $table->unique('token_hash');
            $table->index(['organization_id', 'client_id', 'channel', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_channel_link_tokens');
    }
};
