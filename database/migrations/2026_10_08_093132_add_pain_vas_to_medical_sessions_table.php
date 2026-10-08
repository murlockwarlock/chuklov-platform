<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_sessions', function (Blueprint $table): void {
            $table->text('pain_vas')->nullable()->after('pain');
        });
    }

    public function down(): void
    {
        Schema::table('medical_sessions', function (Blueprint $table): void {
            $table->dropColumn('pain_vas');
        });
    }
};
