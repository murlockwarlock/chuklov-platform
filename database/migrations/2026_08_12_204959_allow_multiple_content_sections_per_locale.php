<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_sections', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'section_key', 'locale']);
            $table->index(['organization_id', 'section_key', 'locale']);
        });
    }

    public function down(): void {}
};
