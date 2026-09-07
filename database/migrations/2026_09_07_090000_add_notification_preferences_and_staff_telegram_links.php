<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->boolean('notifications_enabled')->default(true)->after('is_active');
        });

        Schema::create('organization_channel_link_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id');
            $table->string('channel', 32);
            $table->string('flow', 64);
            $table->string('token_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['organization_id', 'user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->cascadeOnDelete();
            $table->unique('token_hash');
            $table->index(['organization_id', 'user_id', 'channel', 'consumed_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(<<<'SQL'
                CREATE UNIQUE INDEX organization_channel_link_tokens_active_scope_unique
                ON organization_channel_link_tokens (organization_id, user_id, channel, flow)
                WHERE consumed_at IS NULL
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('organization_channel_link_tokens')
            && Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS organization_channel_link_tokens_active_scope_unique');
        }

        Schema::dropIfExists('organization_channel_link_tokens');
        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->dropColumn('notifications_enabled');
        });
    }
};
