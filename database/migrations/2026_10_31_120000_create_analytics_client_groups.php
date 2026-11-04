<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_client_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('group_type', 16);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->json('rule_definition')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active', 'sort_order']);
        });

        Schema::create('analytics_client_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('analytics_client_group_id');
            $table->foreignId('client_id');
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'analytics_client_group_id', 'client_id'], 'analytics_group_membership_unique');
            $table->foreign(['organization_id', 'analytics_client_group_id'], 'analytics_group_members_org_group_fk')
                ->references(['organization_id', 'id'])
                ->on('analytics_client_groups')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'client_id'], 'analytics_group_members_org_client_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE analytics_client_groups ADD CONSTRAINT analytics_client_groups_type_check CHECK (group_type IN ('manual', 'dynamic'))");
            DB::statement("ALTER TABLE analytics_client_groups ADD CONSTRAINT analytics_client_groups_definition_check CHECK ((group_type = 'manual' AND rule_definition IS NULL) OR (group_type = 'dynamic' AND rule_definition IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_client_group_members');
        Schema::dropIfExists('analytics_client_groups');
    }
};
