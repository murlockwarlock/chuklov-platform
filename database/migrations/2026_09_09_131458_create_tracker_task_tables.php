<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracker_plan_versions', function (Blueprint $table): void {
            $table->text('monthly_practice')->nullable();
        });

        Schema::table('tracker_entitlements', function (Blueprint $table): void {
            $table->text('applied_monthly_practice')->nullable();
        });

        Schema::create('tracker_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('title', 160);
            $table->string('task_type', 32);
            $table->string('frequency', 16);
            $table->unsignedTinyInteger('week_day')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'active', 'starts_on', 'ends_on']);
        });

        Schema::create('tracker_task_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('tracker_task_id');
            $table->foreignId('client_id');
            $table->date('period_start');
            $table->string('status', 32);
            $table->text('comment')->nullable();
            $table->timestampTz('recorded_at');
            $table->string('source', 32)->default('portal');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'tracker_task_id', 'client_id', 'period_start']);
            $table->foreign(['organization_id', 'tracker_task_id'])
                ->references(['organization_id', 'id'])
                ->on('tracker_tasks')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'period_start']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE tracker_tasks ADD CONSTRAINT tracker_tasks_shape_check CHECK (char_length(trim(title)) BETWEEN 1 AND 160 AND task_type IN ('exercise', 'hydration', 'practice', 'other') AND frequency IN ('daily', 'weekly') AND ((frequency = 'daily' AND week_day IS NULL) OR (frequency = 'weekly' AND week_day BETWEEN 1 AND 7)) AND (ends_on IS NULL OR ends_on >= starts_on))");
            DB::statement("ALTER TABLE tracker_task_entries ADD CONSTRAINT tracker_task_entries_status_check CHECK (status IN ('completed', 'not_completed'))");
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
                'referral.link.visited',
                'payment.provider.event.prepared',
                'tracker.task.daily_assigned',
                'tracker.task.weekly_assigned',
            ];
            $quotedEvents = implode(', ', array_map(static fn (string $event): string => "'".$event."'", $events));
            DB::statement("ALTER TABLE scenario_rules ADD CONSTRAINT operational_scenario_rules_event_ck CHECK (trigger_event IN ({$quotedEvents}))");
            DB::statement("ALTER TABLE scenario_actions ADD CONSTRAINT operational_scenario_actions_event_ck CHECK (trigger_event IN ({$quotedEvents}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_task_entries');
        Schema::dropIfExists('tracker_tasks');
        Schema::table('tracker_entitlements', function (Blueprint $table): void {
            $table->dropColumn('applied_monthly_practice');
        });
        Schema::table('tracker_plan_versions', function (Blueprint $table): void {
            $table->dropColumn('monthly_practice');
        });
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS operational_scenario_actions_event_ck');
            DB::statement("ALTER TABLE scenario_actions ADD CONSTRAINT booking_scenario_actions_event_ck CHECK (trigger_event IN ({$this->quotedEvents($this->previousScenarioEventTypes())}))");
            DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS operational_scenario_rules_event_ck');
            DB::statement("ALTER TABLE scenario_rules ADD CONSTRAINT booking_scenario_rules_event_ck CHECK (trigger_event IN ({$this->quotedEvents($this->previousScenarioEventTypes())}))");
        }
    }

    private function previousScenarioEventTypes(): array
    {
        return [
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
            'referral.link.visited',
            'payment.provider.event.prepared',
        ];
    }

    private function quotedEvents(array $events): string
    {
        return implode(', ', array_map(
            static fn (string $event): string => "'".str_replace("'", "''", $event)."'",
            $events,
        ));
    }
};
