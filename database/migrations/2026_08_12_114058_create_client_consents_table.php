<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->enum('subject', ['offer', 'privacy', 'medical_disclaimer', 'marketing']);
            $table->string('version', 64);
            $table->boolean('is_required');
            $table->boolean('granted');
            $table->string('evidence', 64);
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'client_id', 'subject', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_consents');
    }
};
