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

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_specialist_overlap');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_valid_range');
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
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_specialist_overlap');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_valid_range');
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_valid_range '
            .'CHECK (starts_at < ends_at AND ends_at <= blocking_ends_at AND party_size > 0 AND '
            ."visit_format IN ('office', 'home', 'online') AND "
            ."status IN ('requested', 'pending_review', 'confirmed', 'cancelled', 'completed') AND "
            ."payment_status IN ('unpaid', 'pending', 'partially_paid', 'paid', 'refunded') AND "
            ."source IN ('crm', 'portal'))",
        );
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_no_specialist_overlap '
            .'EXCLUDE USING gist ('
            .'organization_id WITH =, specialist_id WITH =, '
            ."tstzrange(starts_at, blocking_ends_at, '[)') WITH &&"
            .") WHERE (status IN ('requested', 'pending_review', 'confirmed'))",
        );
    }
};
