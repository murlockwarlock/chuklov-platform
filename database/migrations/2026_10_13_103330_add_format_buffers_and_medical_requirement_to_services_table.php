<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedInteger('buffer_office_minutes')->nullable();
            $table->unsignedInteger('buffer_home_minutes')->nullable();
            $table->unsignedInteger('buffer_online_minutes')->nullable();
            $table->boolean('requires_medical_profile')->default(true);
        });
    }

    public function down(): void
    {
        throw new LogicException('Service configuration is forward-only; retain it when rolling back application code.');
    }
};
