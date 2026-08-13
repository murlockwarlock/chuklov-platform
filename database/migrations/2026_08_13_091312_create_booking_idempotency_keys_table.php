<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('booking_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('actor_type', 32);
            $table->string('actor_scope', 128);
            $table->foreignId('actor_user_id')->nullable();
            $table->foreignId('actor_client_id')->nullable();
            $table->string('request_hash', 64);
            $table->foreignId('booking_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['organization_id', 'idempotency_key']);
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'actor_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'actor_client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'booking_id'])
                ->references(['organization_id', 'id'])
                ->on('bookings')
                ->restrictOnDelete();
            $table->index(['organization_id', 'actor_scope']);
            $table->index(['organization_id', 'expires_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE booking_idempotency_keys ADD CONSTRAINT booking_idempotency_keys_actor_shape '
                ."CHECK ((actor_type = 'user' AND actor_user_id IS NOT NULL AND actor_client_id IS NULL) OR "
                ."(actor_type = 'client' AND actor_user_id IS NULL AND actor_client_id IS NOT NULL))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_idempotency_keys');
    }
};
