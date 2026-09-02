<?php

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\EnsureAppointmentReminderDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('recipient_type', 24);
            $table->unsignedInteger('offset_value');
            $table->string('offset_unit', 16);
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'recipient_type', 'offset_value', 'offset_unit'], 'appointment_reminders_org_recipient_offset_uq');
            $table->index(['organization_id', 'recipient_type', 'is_enabled'], 'appointment_reminders_org_recipient_enabled_ix');
        });

        Schema::table('scenario_rules', function (Blueprint $table): void {
            $table->boolean('system_managed')->default(false)->after('is_enabled');
            $table->index(['organization_id', 'system_managed', 'trigger_event'], 'scenario_rules_org_system_event_ix');
        });

        Schema::table('scenario_actions', function (Blueprint $table): void {
            $table->string('kind', 32)->default('scenario')->after('scenario_rule_id');
            $table->foreignId('appointment_reminder_id')->nullable()->after('recipient_user_id');
            $table->foreignId('booking_id')->nullable()->after('appointment_reminder_id');
            $table->timestampTz('booking_starts_at')->nullable()->after('booking_id');
            $table->foreign(['organization_id', 'appointment_reminder_id'], 'scenario_actions_org_reminder_fk')
                ->references(['organization_id', 'id'])
                ->on('appointment_reminders')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'booking_id'], 'scenario_actions_org_booking_fk')
                ->references(['organization_id', 'id'])
                ->on('bookings')
                ->restrictOnDelete();
            $table->index(['organization_id', 'booking_id', 'status', 'scheduled_for'], 'scenario_actions_booking_due_ix');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE appointment_reminders ADD CONSTRAINT appointment_reminders_offset_ck CHECK (recipient_type IN ('client', 'specialist') AND offset_value > 0 AND offset_unit IN ('minutes', 'hours', 'days'))",
            );
            DB::statement(
                "ALTER TABLE scenario_actions ADD CONSTRAINT scenario_actions_kind_ck CHECK ((kind = 'scenario' AND appointment_reminder_id IS NULL AND booking_id IS NULL AND booking_starts_at IS NULL) OR (kind = 'appointment_reminder' AND appointment_reminder_id IS NOT NULL AND booking_id IS NOT NULL AND booking_starts_at IS NOT NULL))",
            );
        }

        Organization::query()->orderBy('id')->each(function (Organization $organization): void {
            app(EnsureAppointmentReminderDefaults::class)->handle($organization);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS scenario_actions_kind_ck');
            DB::statement('ALTER TABLE appointment_reminders DROP CONSTRAINT IF EXISTS appointment_reminders_offset_ck');
        }

        Schema::table('scenario_actions', function (Blueprint $table): void {
            $table->dropForeign('scenario_actions_org_booking_fk');
            $table->dropForeign('scenario_actions_org_reminder_fk');
            $table->dropIndex('scenario_actions_booking_due_ix');
            $table->dropColumn(['kind', 'appointment_reminder_id', 'booking_id', 'booking_starts_at']);
        });

        Schema::table('scenario_rules', function (Blueprint $table): void {
            $table->dropIndex('scenario_rules_org_system_event_ix');
            $table->dropColumn('system_managed');
        });

        Schema::dropIfExists('appointment_reminders');
    }
};
