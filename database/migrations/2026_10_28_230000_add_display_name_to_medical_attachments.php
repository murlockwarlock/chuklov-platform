<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table): void {
            $table->string('display_name', 255)->nullable()->after('original_filename');
        });
    }

    public function down(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table): void {
            $table->dropColumn('display_name');
        });
    }
};
