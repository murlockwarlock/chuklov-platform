<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS operational_scenario_rules_event_ck');
        DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS operational_scenario_actions_event_ck');

        $events = [
            'booking.completed',
            'onboarding.started',
            'finance.obligation.created',
            'survey.completed',
            'TEST_STAGNATION_DETECTED',
            'b2b.lead.submitted',
            'b2b.sales_call.ready',
            'booking.confirmed',
            'booking.created',
            'booking.rescheduled',
            'booking.cancelled',
            'booking.rejected',
            'companion.requested_specialist',
            'companion.fallback_failed',
            'broadcast.delivery_failed',
            'feedback.submitted',
            'referral.payout.requested',
            'referral.payout.status_changed',
            'booking.home_visit.changed',
            'ai.evaluation.failed',
            'knowledge.ingestion.failed',
            'referral.link.visited',
            'payment.provider.event.prepared',
            'tracker.task.daily_assigned',
            'tracker.task.weekly_assigned',
        ];
        $quotedEvents = implode(', ', array_map(
            static fn (string $event): string => "'".str_replace("'", "''", $event)."'",
            $events,
        ));

        DB::statement("ALTER TABLE scenario_rules ADD CONSTRAINT operational_scenario_rules_event_ck CHECK (trigger_event IN ({$quotedEvents}))");
        DB::statement("ALTER TABLE scenario_actions ADD CONSTRAINT operational_scenario_actions_event_ck CHECK (trigger_event IN ({$quotedEvents}))");
    }

    public function down(): void
    {
    }
};
