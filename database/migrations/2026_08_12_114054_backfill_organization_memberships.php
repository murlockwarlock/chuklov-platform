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
                $memberships = [];

                foreach ($users as $user) {
                    $memberships[] = [
                        'organization_id' => $user->organization_id,
                        'user_id' => $user->id,
                        'role' => $user->is_admin ? 'administrator' : 'staff',
                        'is_active' => true,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ];
                }

                if ($memberships !== []) {
                    DB::table('organization_memberships')->upsert(
                        $memberships,
                        ['organization_id', 'user_id'],
                        ['role', 'is_active', 'updated_at'],
                    );
                }
            });
    }

    public function down(): void
    {
        throw new LogicException('The legacy membership backfill cannot be rolled back safely.');
    }
};
