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

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_payment_requirement_check');
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_payment_requirement_check '
            .'CHECK ((payment_requirement IS NULL AND payment_requirement_amount_minor IS NULL AND payment_requirement_currency IS NULL) OR '
            ."(payment_requirement = 'full_payment' AND payment_requirement_amount_minor IS NULL AND payment_requirement_currency IS NULL) OR "
            ."(payment_requirement = 'transport_deposit' AND payment_requirement_amount_minor IS NOT NULL AND payment_requirement_amount_minor >= 0 AND payment_requirement_currency ~ '^[A-Z]{3}$'))",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_payment_requirement_check');
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_payment_requirement_check '
            .'CHECK (payment_requirement IS NULL OR '
            ."(payment_requirement = 'full_payment' AND payment_requirement_amount_minor IS NULL AND payment_requirement_currency IS NULL) OR "
            ."(payment_requirement = 'transport_deposit' AND payment_requirement_amount_minor IS NOT NULL AND payment_requirement_amount_minor >= 0 AND payment_requirement_currency ~ '^[A-Z]{3}$'))",
        );
    }
};
