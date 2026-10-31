<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_goal_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->string('group_name', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'name']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'is_active', 'sort_order']);
        });

        Schema::create('tracker_task_goal_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('tracker_task_id');
            $table->foreignId('tracker_goal_tag_id');
            $table->timestampsTz();
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'tracker_task_id'])
                ->references(['organization_id', 'id'])
                ->on('tracker_tasks')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'tracker_goal_tag_id'])
                ->references(['organization_id', 'id'])
                ->on('tracker_goal_tags')
                ->restrictOnDelete();
            $table->unique(['organization_id', 'tracker_task_id', 'tracker_goal_tag_id'], 'tracker_task_goal_tags_unique');
            $table->index(['organization_id', 'client_id', 'tracker_goal_tag_id']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Tracker goal tags are retained by forward-only migrations.');
    }
};
