<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_days', function (Blueprint $table): void {
            $table->unsignedBigInteger('specialist_id')->nullable();
            $table->foreign(['organization_id', 'specialist_id'], 'location_days_specialist_tenant_fk')
                ->references(['organization_id', 'id'])->on('specialists')->restrictOnDelete();
            $table->index(['organization_id', 'specialist_id', 'is_active'], 'location_days_specialist_active_idx');
        });
    }

    public function down(): void
    {
        throw new LogicException('Specialist travel assignments are retained by forward-only migrations.');
    }
};
