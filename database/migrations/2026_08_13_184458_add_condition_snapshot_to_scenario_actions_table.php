<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenario_actions', function (Blueprint $table): void {
            $table->json('condition_snapshot')->default('[]')->after('rule_version');
        });

        DB::table('scenario_actions')
            ->select(['id', 'organization_id', 'scenario_rule_id'])
            ->orderBy('id')
            ->chunkById(500, function ($actions): void {
                foreach ($actions as $action) {
                    $conditions = DB::table('scenario_rules')
                        ->where('organization_id', $action->organization_id)
                        ->where('id', $action->scenario_rule_id)
                        ->value('conditions');

                    DB::table('scenario_actions')
                        ->where('id', $action->id)
                        ->update(['condition_snapshot' => $conditions ?? '[]']);
                }
            });
    }

    public function down(): void
    {
        Schema::table('scenario_actions', function (Blueprint $table): void {
            $table->dropColumn('condition_snapshot');
        });
    }
};
