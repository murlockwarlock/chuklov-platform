<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('companion_message_attachments', function (Blueprint $table): void {
                $table->foreignId('turn_id')->nullable()->change();
            });

            return;
        }

        Schema::table('companion_message_attachments', function (Blueprint $table): void {
            $table->dropForeign('companion_message_attachments_org_turn_fk');
        });

        Schema::table('companion_message_attachments', function (Blueprint $table): void {
            $table->foreignId('turn_id')->nullable()->change();
        });

        Schema::table('companion_message_attachments', function (Blueprint $table): void {
            $table->foreign(['organization_id', 'turn_id'], 'companion_message_attachments_org_turn_fk')
                ->references(['organization_id', 'id'])
                ->on('companion_turns')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('companion_message_attachments', function (Blueprint $table): void {
                $table->foreignId('turn_id')->nullable(false)->change();
            });

            return;
        }

        Schema::table('companion_message_attachments', function (Blueprint $table): void {
            $table->dropForeign('companion_message_attachments_org_turn_fk');
        });

        Schema::table('companion_message_attachments', function (Blueprint $table): void {
            $table->foreignId('turn_id')->nullable(false)->change();
        });

        Schema::table('companion_message_attachments', function (Blueprint $table): void {
            $table->foreign(['organization_id', 'turn_id'], 'companion_message_attachments_org_turn_fk')
                ->references(['organization_id', 'id'])
                ->on('companion_turns')
                ->cascadeOnDelete();
        });
    }
};
