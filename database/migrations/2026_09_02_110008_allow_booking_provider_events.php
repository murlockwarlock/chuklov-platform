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

        DB::statement('ALTER TABLE integration_events DROP CONSTRAINT IF EXISTS b2b_integration_events_type_ck');
        DB::statement('ALTER TABLE integration_events DROP CONSTRAINT IF EXISTS integration_events_type_check');
        DB::statement(
            'ALTER TABLE integration_events ADD CONSTRAINT booking_integration_events_type_ck CHECK (event_type IN ('
            ."'finance.obligation.settled', 'b2b.sales_call.provider_sync', 'booking.provider_sync'))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE integration_events DROP CONSTRAINT IF EXISTS booking_integration_events_type_ck');
        DB::statement(
            'ALTER TABLE integration_events ADD CONSTRAINT b2b_integration_events_type_ck CHECK (event_type IN ('
            ."'finance.obligation.settled', 'b2b.sales_call.provider_sync'))"
        );
    }
};
