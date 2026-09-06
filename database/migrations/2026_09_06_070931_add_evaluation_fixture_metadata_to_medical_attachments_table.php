<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table): void {
            $table->string('evaluation_fixture_key', 120)->nullable()->after('sha256_checksum');
            $table->string('evaluation_fixture_role', 16)->nullable()->after('evaluation_fixture_key');
            $table->index(['organization_id', 'evaluation_fixture_key']);
            $table->unique(
                ['organization_id', 'evaluation_fixture_key', 'evaluation_fixture_role'],
                'medical_attachments_evaluation_fixture_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table): void {
            $table->dropUnique('medical_attachments_evaluation_fixture_unique');
            $table->dropIndex(['organization_id', 'evaluation_fixture_key']);
            $table->dropColumn(['evaluation_fixture_key', 'evaluation_fixture_role']);
        });
    }
};
