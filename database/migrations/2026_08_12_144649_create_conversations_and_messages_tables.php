<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('channel', 32);
            $table->string('external_key', 191)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'channel', 'external_key']);
            $table->index(['organization_id', 'client_id', 'last_message_at']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
        });

        Schema::create('conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversation_id');
            $table->foreignId('client_id');
            $table->string('channel', 32);
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('author_type', ['client', 'staff', 'ai', 'system']);
            $table->string('external_id', 191)->nullable();
            $table->text('body')->nullable();
            $table->json('metadata');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['organization_id', 'conversation_id', 'id']);
            $table->unique(['organization_id', 'channel', 'external_id']);
            $table->index(['organization_id', 'client_id', 'occurred_at']);
            $table->foreign(['organization_id', 'conversation_id'])
                ->references(['organization_id', 'id'])
                ->on('conversations')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
