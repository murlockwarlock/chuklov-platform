<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('organization_id')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    DB::table('organization_memberships')->insert([
                        'organization_id' => $user->organization_id,
                        'user_id' => $user->id,
                        'role' => $user->is_admin ? 'administrator' : 'staff',
                        'is_active' => true,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('organization_memberships')
            ->orderBy('id')
            ->chunkById(500, function ($memberships): void {
                foreach ($memberships as $membership) {
                    DB::table('users')
                        ->where('id', $membership->user_id)
                        ->update([
                            'organization_id' => $membership->organization_id,
                            'is_admin' => in_array($membership->role, ['owner', 'administrator'], true),
                        ]);
                }
            });
    }
};
