<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_client_group_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('analytics_client_group_id');
            $table->unsignedInteger('version');
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('group_type', 16);
            $table->boolean('is_active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('rule_definition')->nullable();
            $table->json('client_ids')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'analytics_client_group_id', 'version'], 'analytics_client_group_versions_unique');
            $table->foreign(['organization_id', 'analytics_client_group_id'], 'analytics_group_versions_org_group_fk')
                ->references(['organization_id', 'id'])
                ->on('analytics_client_groups')
                ->restrictOnDelete();
            $table->index(['organization_id', 'analytics_client_group_id']);
        });

        foreach (DB::table('analytics_client_groups')->get() as $group) {
            $clientIds = DB::table('analytics_client_group_members')
                ->where('organization_id', $group->organization_id)
                ->where('analytics_client_group_id', $group->id)
                ->orderBy('client_id')
                ->pluck('client_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            DB::table('analytics_client_group_versions')->insert([
                'organization_id' => $group->organization_id,
                'analytics_client_group_id' => $group->id,
                'version' => $group->version,
                'name' => $group->name,
                'description' => $group->description,
                'group_type' => $group->group_type,
                'is_active' => $group->is_active,
                'sort_order' => $group->sort_order,
                'rule_definition' => $group->rule_definition,
                'client_ids' => json_encode($clientIds, JSON_THROW_ON_ERROR),
                'created_by_user_id' => $group->updated_by_user_id ?? $group->created_by_user_id,
                'created_at' => $group->updated_at,
                'updated_at' => $group->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_client_group_versions');
    }
};
