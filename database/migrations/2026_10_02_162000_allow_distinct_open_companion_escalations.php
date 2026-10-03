<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS companion_escalations_one_open_per_conversation');
        DB::statement("CREATE UNIQUE INDEX companion_escalations_one_open_per_conversation_reason ON companion_escalations (organization_id, conversation_id, reason) WHERE status = 'open'");
    }

    public function down(): void
    {
        $hasMultipleOpenReasons = DB::table('companion_escalations')
            ->where('status', 'open')
            ->select(['organization_id', 'conversation_id'])
            ->groupBy(['organization_id', 'conversation_id'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasMultipleOpenReasons) {
            throw new RuntimeException('Open Companion escalations must be resolved before this index can be rolled back.');
        }

        DB::statement('DROP INDEX IF EXISTS companion_escalations_one_open_per_conversation_reason');
        DB::statement("CREATE UNIQUE INDEX companion_escalations_one_open_per_conversation ON companion_escalations (organization_id, conversation_id) WHERE status = 'open'");
    }
};
