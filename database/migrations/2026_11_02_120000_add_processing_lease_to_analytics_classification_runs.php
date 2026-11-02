<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_classification_runs', function (Blueprint $table): void {
            $table->string('processing_lease_token', 64)->nullable()->after('attempt_count');
            $table->timestampTz('processing_lease_expires_at')->nullable()->after('processing_lease_token');
            $table->index(['organization_id', 'status', 'processing_lease_expires_at'], 'analytics_classification_processing_lease_index');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_classification_runs', function (Blueprint $table): void {
            $table->dropIndex('analytics_classification_processing_lease_index');
            $table->dropColumn(['processing_lease_token', 'processing_lease_expires_at']);
        });
    }
};
