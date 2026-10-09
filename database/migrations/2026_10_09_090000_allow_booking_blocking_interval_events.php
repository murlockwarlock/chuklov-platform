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

        DB::statement('ALTER TABLE booking_events DROP CONSTRAINT IF EXISTS booking_events_actor_shape');
        DB::statement(
            'ALTER TABLE booking_events ADD CONSTRAINT booking_events_actor_shape '
            ."CHECK (event_type IN ('created', 'status_changed', 'rescheduled', 'cancelled', 'completed', 'no_show', 'meeting_link_updated', 'blocking_interval_updated', 'party_size_updated') AND "
            ."((actor_type = 'user' AND actor_user_id IS NOT NULL AND actor_client_id IS NULL) OR "
            ."(actor_type = 'client' AND actor_user_id IS NULL AND actor_client_id IS NOT NULL) OR "
            ."(actor_type = 'system' AND actor_user_id IS NULL AND actor_client_id IS NULL)))",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE booking_events DROP CONSTRAINT IF EXISTS booking_events_actor_shape');
        DB::statement(
            'ALTER TABLE booking_events ADD CONSTRAINT booking_events_actor_shape '
            ."CHECK (event_type IN ('created', 'status_changed', 'rescheduled', 'cancelled', 'completed', 'no_show', 'meeting_link_updated') AND "
            ."((actor_type = 'user' AND actor_user_id IS NOT NULL AND actor_client_id IS NULL) OR "
            ."(actor_type = 'client' AND actor_user_id IS NULL AND actor_client_id IS NOT NULL) OR "
            ."(actor_type = 'system' AND actor_user_id IS NULL AND actor_client_id IS NULL)))",
        );
    }
};
