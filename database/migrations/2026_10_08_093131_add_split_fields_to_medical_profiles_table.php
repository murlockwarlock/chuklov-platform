<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_profiles', function (Blueprint $table): void {
            $table->text('complaints')->nullable()->after('complaints_goals');
            $table->text('goals')->nullable()->after('complaints');
            $table->text('operations')->nullable()->after('operations_injuries');
            $table->text('injuries')->nullable()->after('operations');
        });
    }

    public function down(): void
    {
        Schema::table('medical_profiles', function (Blueprint $table): void {
            $table->dropColumn(['complaints', 'goals', 'operations', 'injuries']);
        });
    }
};
