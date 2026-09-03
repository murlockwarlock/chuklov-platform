<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $organization = DB::table('organizations')
            ->where('slug', 'chuklov')
            ->where('timezone', 'Asia/Bangkok')
            ->first(['id']);

        if ($organization === null) {
            return;
        }

        DB::table('organizations')
            ->where('id', $organization->id)
            ->update([
                'timezone' => 'Asia/Almaty',
                'updated_at' => now(),
            ]);
    }

    public function down(): void {}
};
