<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MilestoneThreeDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_m3_schema_contains_only_organization_scoped_catalog_and_crm_records(): void
    {
        self::assertTrue(Schema::hasTable('specialists'));
        self::assertTrue(Schema::hasTable('client_booking_restrictions'));
        self::assertTrue(Schema::hasTable('content_sections'));
        self::assertTrue(Schema::hasColumn('specialists', 'staff_user_id'));
        self::assertTrue(Schema::hasColumn('specialists', 'timezone'));
        self::assertTrue(Schema::hasColumn('services', 'price_minor'));
        self::assertTrue(Schema::hasColumn('services', 'price_currency'));
        self::assertTrue(Schema::hasColumn('services', 'catalog_type'));
        self::assertFalse(Schema::hasTable('medical_profiles'));
        self::assertFalse(Schema::hasTable('bookings'));
        self::assertFalse(Schema::hasTable('payments'));
    }
}
