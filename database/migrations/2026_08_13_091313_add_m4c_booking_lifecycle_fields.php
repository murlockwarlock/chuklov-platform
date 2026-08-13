<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('payment_requirement', 32)->nullable()->after('payment_status');
            $table->unsignedBigInteger('payment_requirement_amount_minor')->nullable()->after('payment_requirement');
            $table->char('payment_requirement_currency', 3)->nullable()->after('payment_requirement_amount_minor');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_specialist_overlap');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_valid_range');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_payment_requirement_check');
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_valid_range '
            .'CHECK (starts_at < ends_at AND ends_at <= blocking_ends_at AND party_size BETWEEN 1 AND 20 AND '
            ."visit_format IN ('office', 'home', 'online') AND "
            ."status IN ('requested', 'pending_review', 'confirmed', 'rejected', 'cancelled', 'completed', 'no_show') AND "
            ."payment_status IN ('unpaid', 'pending', 'partially_paid', 'paid', 'refunded') AND "
            ."source IN ('crm', 'portal'))",
        );
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_payment_requirement_check '
            .'CHECK (payment_requirement IS NULL OR '
            ."(payment_requirement = 'full_payment' AND payment_requirement_amount_minor IS NULL AND payment_requirement_currency IS NULL) OR "
            ."(payment_requirement = 'transport_deposit' AND payment_requirement_amount_minor IS NOT NULL AND payment_requirement_amount_minor >= 0 AND payment_requirement_currency ~ '^[A-Z]{3}$'))",
        );
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_no_specialist_overlap '
            .'EXCLUDE USING gist ('
            .'organization_id WITH =, specialist_id WITH =, '
            ."tstzrange(starts_at, blocking_ends_at, '[)') WITH &&"
            .") WHERE (status IN ('requested', 'confirmed'))",
        );

        DB::statement('ALTER TABLE booking_events DROP CONSTRAINT IF EXISTS booking_events_actor_shape');
        DB::statement(
            'ALTER TABLE booking_events ADD CONSTRAINT booking_events_actor_shape '
            ."CHECK (event_type IN ('created', 'status_changed', 'rescheduled', 'cancelled', 'completed', 'no_show', 'meeting_link_updated') AND "
            ."((actor_type = 'user' AND actor_user_id IS NOT NULL AND actor_client_id IS NULL) OR "
            ."(actor_type = 'client' AND actor_user_id IS NULL AND actor_client_id IS NOT NULL) OR "
            ."(actor_type = 'system' AND actor_user_id IS NULL AND actor_client_id IS NULL)))",
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_specialist_overlap');
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_valid_range');
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_payment_requirement_check');
            DB::statement(
                'ALTER TABLE bookings ADD CONSTRAINT bookings_valid_range '
                .'CHECK (starts_at < ends_at AND ends_at <= blocking_ends_at AND party_size > 0 AND '
                ."visit_format IN ('office', 'home', 'online') AND "
                ."status IN ('requested', 'pending_review', 'confirmed', 'rejected', 'cancelled', 'completed') AND "
                ."payment_status IN ('unpaid', 'pending', 'partially_paid', 'paid', 'refunded') AND "
                ."source IN ('crm', 'portal'))",
            );
            DB::statement(
                'ALTER TABLE bookings ADD CONSTRAINT bookings_no_specialist_overlap '
                .'EXCLUDE USING gist ('
                .'organization_id WITH =, specialist_id WITH =, '
                ."tstzrange(starts_at, blocking_ends_at, '[)') WITH &&"
                .") WHERE (status IN ('requested', 'confirmed'))",
            );
            DB::statement('ALTER TABLE booking_events DROP CONSTRAINT IF EXISTS booking_events_actor_shape');
            DB::statement(
                'ALTER TABLE booking_events ADD CONSTRAINT booking_events_actor_shape '
                ."CHECK (event_type IN ('created', 'status_changed', 'rescheduled', 'cancelled', 'completed') AND "
                ."((actor_type = 'user' AND actor_user_id IS NOT NULL AND actor_client_id IS NULL) OR "
                ."(actor_type = 'client' AND actor_user_id IS NULL AND actor_client_id IS NOT NULL) OR "
                ."(actor_type = 'system' AND actor_user_id IS NULL AND actor_client_id IS NULL)))",
            );
        }

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_requirement',
                'payment_requirement_amount_minor',
                'payment_requirement_currency',
            ]);
        });
    }
};
