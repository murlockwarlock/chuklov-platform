<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_form_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft');
            $table->json('fields');
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'version'], 'b2b_form_config_org_version_uq');
            $table->index(['organization_id', 'status', 'version'], 'b2b_form_config_status_ix');
        });

        Schema::table('b2b_leads', function (Blueprint $table): void {
            $table->unsignedInteger('form_version')->nullable();
            $table->json('form_schema')->nullable();
            $table->json('form_answers')->nullable();
            $table->index(['organization_id', 'form_version'], 'b2b_leads_form_version_ix');
        });
    }

    public function down(): void
    {
        throw new LogicException('B2B form configuration history is retained by forward-only migrations.');
    }
};
