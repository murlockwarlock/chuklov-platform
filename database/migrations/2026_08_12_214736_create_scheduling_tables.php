<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialist_working_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('specialist_id');
            $table->unsignedSmallInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'specialist_id'])
                ->references(['organization_id', 'id'])
                ->on('specialists')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'specialist_id', 'weekday', 'is_active']);
        });

        Schema::create('schedule_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('specialist_id');
            $table->date('exception_date');
            $table->string('exception_type', 32);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('reason', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'specialist_id'])
                ->references(['organization_id', 'id'])
                ->on('specialists')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'specialist_id', 'exception_date', 'is_active']);
        });

        Schema::create('unavailable_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('specialist_id');
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'specialist_id'])
                ->references(['organization_id', 'id'])
                ->on('specialists')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'created_by_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
            $table->index(['organization_id', 'specialist_id', 'starts_at', 'ends_at']);
        });

        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('specialist_id');
            $table->foreignId('service_id');
            $table->string('calendar_uid', 128);
            $table->string('visit_format', 32);
            $table->string('status', 32);
            $table->string('payment_status', 32);
            $table->string('source', 32);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('blocking_ends_at');
            $table->string('schedule_timezone', 64);
            $table->string('client_timezone', 64)->nullable();
            $table->string('location', 500)->nullable();
            $table->string('meeting_link_mode', 32)->nullable();
            $table->string('meeting_url', 2000)->nullable();
            $table->unsignedSmallInteger('party_size')->default(1);
            $table->unsignedInteger('event_version')->default(1);
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'calendar_uid']);
            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'specialist_id'])
                ->references(['organization_id', 'id'])
                ->on('specialists')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'service_id'])
                ->references(['organization_id', 'id'])
                ->on('services')
                ->restrictOnDelete();
            $table->index(['organization_id', 'specialist_id', 'starts_at']);
            $table->index(['organization_id', 'client_id', 'starts_at']);
            $table->index(['organization_id', 'status', 'starts_at']);
        });

        Schema::create('booking_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id');
            $table->string('event_type', 32);
            $table->string('actor_type', 32);
            $table->foreignId('actor_user_id')->nullable();
            $table->foreignId('actor_client_id')->nullable();
            $table->json('old_values');
            $table->json('new_values');
            $table->string('reason', 500)->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'booking_id'])
                ->references(['organization_id', 'id'])
                ->on('bookings')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'actor_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'actor_client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->index(['organization_id', 'booking_id', 'occurred_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement(
            'ALTER TABLE specialist_working_hours ADD CONSTRAINT specialist_working_hours_weekday_range '
            .'CHECK (weekday BETWEEN 1 AND 7 AND start_time < end_time)'
        );
        DB::statement(
            'ALTER TABLE specialist_working_hours ADD CONSTRAINT specialist_working_hours_no_overlap '
            .'EXCLUDE USING gist ('
            .'organization_id WITH =, specialist_id WITH =, weekday WITH =, '
            .'int4range((extract(hour from start_time) * 60 + extract(minute from start_time))::integer, '
            ."(extract(hour from end_time) * 60 + extract(minute from end_time))::integer, '[)') WITH &&"
            .') WHERE (is_active)'
        );
        DB::statement(
            'ALTER TABLE schedule_exceptions ADD CONSTRAINT schedule_exceptions_valid_window '
            ."CHECK (exception_type IN ('day_off', 'custom_window') AND "
            ."((exception_type = 'day_off' AND start_time IS NULL AND end_time IS NULL) OR "
            ."(exception_type = 'custom_window' AND start_time IS NOT NULL AND end_time IS NOT NULL AND start_time < end_time)))"
        );
        DB::statement(
            'ALTER TABLE schedule_exceptions ADD CONSTRAINT schedule_exceptions_no_overlap '
            .'EXCLUDE USING gist ('
            .'organization_id WITH =, specialist_id WITH =, exception_date WITH =, '
            ."int4range(CASE WHEN exception_type = 'day_off' THEN 0 ELSE (extract(hour from start_time) * 60 + extract(minute from start_time))::integer END, "
            ."CASE WHEN exception_type = 'day_off' THEN 1440 ELSE (extract(hour from end_time) * 60 + extract(minute from end_time))::integer END, '[)') WITH &&"
            .') WHERE (is_active)'
        );
        DB::statement(
            'ALTER TABLE unavailable_periods ADD CONSTRAINT unavailable_periods_valid_range '
            .'CHECK (starts_at < ends_at)'
        );
        DB::statement(
            'ALTER TABLE unavailable_periods ADD CONSTRAINT unavailable_periods_no_overlap '
            .'EXCLUDE USING gist ('
            .'organization_id WITH =, specialist_id WITH =, '
            ."tstzrange(starts_at, ends_at, '[)') WITH &&"
            .')'
        );
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_valid_range '
            .'CHECK (starts_at < ends_at AND ends_at <= blocking_ends_at AND party_size > 0 AND '
            ."visit_format IN ('office', 'home', 'online') AND "
            ."status IN ('requested', 'pending_review', 'confirmed', 'cancelled', 'completed') AND "
            ."payment_status IN ('unpaid', 'pending', 'partially_paid', 'paid', 'refunded') AND "
            ."source IN ('crm', 'portal'))"
        );
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_online_link_mode '
            ."CHECK (meeting_link_mode IS NULL OR meeting_link_mode IN ('auto', 'manual'))"
        );
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_visit_format_link_mode '
            ."CHECK ((visit_format = 'online') OR meeting_link_mode IS NULL)"
        );
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_visit_format_meeting_url '
            ."CHECK (meeting_url IS NULL OR visit_format = 'online')"
        );
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_no_specialist_overlap '
            .'EXCLUDE USING gist ('
            .'organization_id WITH =, specialist_id WITH =, '
            ."tstzrange(starts_at, blocking_ends_at, '[)') WITH &&"
            .") WHERE (status IN ('requested', 'pending_review', 'confirmed'))"
        );
        DB::statement(
            'ALTER TABLE booking_events ADD CONSTRAINT booking_events_actor_shape '
            ."CHECK (event_type IN ('created', 'status_changed', 'rescheduled', 'cancelled', 'completed') AND "
            ."((actor_type = 'user' AND actor_user_id IS NOT NULL AND actor_client_id IS NULL) OR "
            ."(actor_type = 'client' AND actor_user_id IS NULL AND actor_client_id IS NOT NULL) OR "
            ."(actor_type = 'system' AND actor_user_id IS NULL AND actor_client_id IS NULL)))"
        );
        DB::statement(
            'CREATE OR REPLACE FUNCTION prevent_booking_event_mutation() RETURNS trigger LANGUAGE plpgsql AS '
            .'$$ BEGIN RAISE EXCEPTION \'booking_events are immutable\'; END; $$'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS booking_events_immutable ON booking_events'
        );
        DB::statement(
            'CREATE TRIGGER booking_events_immutable BEFORE UPDATE OR DELETE ON booking_events '
            .'FOR EACH ROW EXECUTE FUNCTION prevent_booking_event_mutation()'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS booking_events_immutable ON booking_events');
            DB::statement('DROP FUNCTION IF EXISTS prevent_booking_event_mutation()');
        }

        Schema::dropIfExists('booking_events');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('unavailable_periods');
        Schema::dropIfExists('schedule_exceptions');
        Schema::dropIfExists('specialist_working_hours');
    }
};
