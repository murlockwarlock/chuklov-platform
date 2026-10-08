<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('referral_campaign_links')
            ->where('is_active', false)
            ->update([
                'is_active' => true,
                'disabled_at' => null,
                'disabled_by_user_id' => null,
            ]);
    }

    public function down(): void
    {
        // This data repair is intentionally irreversible; reverting it would restore an owner-rejected lifecycle.
    }
};
