<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('specialist_id');
            $table->foreignId('booking_id')->nullable();
            $table->text('pain')->nullable();
            $table->text('tests')->nullable();
            $table->text('observations')->nullable();
            $table->text('root_cause_hypothesis')->nullable();
            $table->text('protocol')->nullable();
            $table->text('result')->nullable();
            $table->unsignedInteger('encryption_key_version')->default(1);
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'specialist_id'])
                ->references(['organization_id', 'id'])
                ->on('specialists')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'booking_id'])
                ->references(['organization_id', 'id'])
                ->on('bookings')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'occurred_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_sessions');
    }
};
