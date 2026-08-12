<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 120);
            $table->string('target_type', 255)->nullable();
            $table->string('target_id', 128)->nullable();
            $table->json('metadata');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['organization_id', 'action', 'occurred_at']);
            $table->index(['organization_id', 'target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
