<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_profile_pains', function (Blueprint $table): void {
            $table->unique(['organization_id', 'client_id', 'id'], 'medical_profile_pains_org_client_id_unique');
        });
        Schema::table('medical_session_pains', function (Blueprint $table): void {
            $table->unique(['organization_id', 'client_id', 'id'], 'medical_session_pains_org_client_id_unique');
        });

        Schema::create('medical_problem_tags', function (Blueprint $table): void {
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

        Schema::create('medical_profile_pain_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_profile_pain_id');
            $table->foreignId('medical_problem_tag_id');
            $table->timestampsTz();

            $table->foreign(['organization_id', 'client_id', 'medical_profile_pain_id'])
                ->references(['organization_id', 'client_id', 'id'])
                ->on('medical_profile_pains')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'medical_problem_tag_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_problem_tags')
                ->restrictOnDelete();
            $table->unique(
                ['organization_id', 'medical_profile_pain_id', 'medical_problem_tag_id'],
                'medical_profile_pain_tags_unique',
            );
            $table->index(['organization_id', 'client_id', 'medical_problem_tag_id']);
        });

        Schema::create('medical_session_pain_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('medical_session_pain_id');
            $table->foreignId('medical_problem_tag_id');
            $table->timestampsTz();

            $table->foreign(['organization_id', 'client_id', 'medical_session_pain_id'])
                ->references(['organization_id', 'client_id', 'id'])
                ->on('medical_session_pains')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'medical_problem_tag_id'])
                ->references(['organization_id', 'id'])
                ->on('medical_problem_tags')
                ->restrictOnDelete();
            $table->unique(
                ['organization_id', 'medical_session_pain_id', 'medical_problem_tag_id'],
                'medical_session_pain_tags_unique',
            );
            $table->index(['organization_id', 'client_id', 'medical_problem_tag_id']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Medical problem tags are retained by forward-only migrations.');
    }
};
