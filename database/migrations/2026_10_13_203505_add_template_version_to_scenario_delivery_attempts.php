<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenario_delivery_attempts', function (Blueprint $table): void {
            $table->foreignId('template_version_id')->nullable();
            $table->index(['organization_id', 'template_version_id'], 'scenario_attempts_org_template_ix');
            $table->foreign(['organization_id', 'template_version_id'], 'scenario_attempts_org_template_fk')
                ->references(['organization_id', 'id'])->on('notification_template_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scenario_delivery_attempts', function (Blueprint $table): void {
            $table->dropForeign('scenario_attempts_org_template_fk');
            $table->dropIndex('scenario_attempts_org_template_ix');
            $table->dropColumn('template_version_id');
        });
    }
};
