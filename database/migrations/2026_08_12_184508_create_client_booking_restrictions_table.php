<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_booking_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('blocked_by_user_id');
            $table->foreignId('unblocked_by_user_id')->nullable();
            $table->string('reason', 500);
            $table->timestampTz('blocked_at')->useCurrent();
            $table->timestampTz('unblocked_at')->nullable();
            $table->timestamps();
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'blocked_by_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'unblocked_by_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'blocked_at']);
            $table->index(['organization_id', 'unblocked_at']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX client_booking_restrictions_one_active '
            .'ON client_booking_restrictions (organization_id, client_id) '
            .'WHERE unblocked_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS client_booking_restrictions_one_active');
        Schema::dropIfExists('client_booking_restrictions');
    }
};
