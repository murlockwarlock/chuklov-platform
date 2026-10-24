<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_profiles', function (Blueprint $table) {
            $table->text('chronic_conditions')->nullable();
            $table->text('clinical_answers')->nullable();
            $table->timestampTz('client_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Medical history must be retained across application rollback.');
    }
};
