<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_events', function (Blueprint $table): void {
            $table->unique(
                ['organization_id', 'id', 'aggregate_id'],
                'integration_events_org_id_id_aggregate_unique',
            );
        });

        Schema::table('referral_commercial_evidence', function (Blueprint $table): void {
            $table->foreign(
                ['organization_id', 'integration_event_id', 'financial_obligation_id'],
                'referral_evidence_event_obligation_fk',
            )
                ->references(['organization_id', 'id', 'aggregate_id'])
                ->on('integration_events')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('referral_commercial_evidence', function (Blueprint $table): void {
            $table->dropForeign('referral_evidence_event_obligation_fk');
        });

        Schema::table('integration_events', function (Blueprint $table): void {
            $table->dropUnique('integration_events_org_id_id_aggregate_unique');
        });
    }
};
