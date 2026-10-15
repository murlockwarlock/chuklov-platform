<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_reminders', function (Blueprint $table): void {
            $table->boolean('medical_profile_prompt')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('appointment_reminders', function (Blueprint $table): void {
            $table->dropColumn('medical_profile_prompt');
        });
    }
};
