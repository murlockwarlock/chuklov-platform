<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table): void {
            $table->dropIndex('medical_attachments_organization_id_scan_status_index');
            $table->dropColumn(['scan_status', 'scan_result_metadata', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table): void {
            $table->string('scan_status', 32)->nullable();
            $table->jsonb('scan_result_metadata')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->index(['organization_id', 'scan_status']);
        });
    }
};
