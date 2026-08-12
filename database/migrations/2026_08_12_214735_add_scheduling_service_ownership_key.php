<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id'], 'services_organization_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropUnique('services_organization_id_id_unique');
        });
    }
};
