<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companion_turns', function (Blueprint $table): void {
            $table->timestampTz('album_assembly_deadline_at')->nullable()->after('burst_expires_at');
            $table->timestampTz('execution_deadline_at')->nullable()->after('processing_lease_expires_at');
            $table->foreignId('album_recovery_message_id')->nullable()->after('outbound_message_id');
            $table->timestampTz('album_incomplete_at')->nullable()->after('failed_at');
            $table->index(
                ['organization_id', 'status', 'album_assembly_deadline_at'],
                'companion_turns_album_deadline_index',
            );
            $table->foreign(['organization_id', 'album_recovery_message_id'], 'companion_turns_org_album_recovery_message_fk')
                ->references(['organization_id', 'id'])
                ->on('conversation_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companion_turns', function (Blueprint $table): void {
            $table->dropForeign('companion_turns_org_album_recovery_message_fk');
            $table->dropIndex('companion_turns_album_deadline_index');
            $table->dropColumn([
                'album_assembly_deadline_at',
                'execution_deadline_at',
                'album_recovery_message_id',
                'album_incomplete_at',
            ]);
        });
    }
};
