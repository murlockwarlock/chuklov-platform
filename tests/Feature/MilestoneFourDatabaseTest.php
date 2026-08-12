<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MilestoneFourDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_m4a_schema_contains_scheduling_foundation_and_no_finance_tables(): void
    {
        self::assertTrue(Schema::hasTable('specialist_working_hours'));
        self::assertTrue(Schema::hasTable('schedule_exceptions'));
        self::assertTrue(Schema::hasTable('unavailable_periods'));
        self::assertTrue(Schema::hasTable('bookings'));
        self::assertTrue(Schema::hasTable('booking_events'));
        self::assertTrue(Schema::hasColumn('bookings', 'blocking_ends_at'));
        self::assertTrue(Schema::hasColumn('bookings', 'payment_status'));
        self::assertTrue(Schema::hasColumn('bookings', 'visit_format'));
        self::assertFalse(Schema::hasTable('payments'));
    }
}
