<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientBookingRestriction;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MilestoneThreeDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgresql_client_search_uses_trigram_extension_indexes_and_case_insensitive_fragments(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The client trigram search assertion requires PostgreSQL.');
        }

        self::assertSame(
            'pg_trgm',
            DB::table('pg_extension')->where('extname', 'pg_trgm')->value('extname'),
        );

        $indexNames = DB::table('pg_indexes')
            ->where('tablename', 'clients')
            ->whereIn('indexname', ['clients_full_name_trgm_idx', 'clients_email_trgm_idx'])
            ->pluck('indexname')
            ->sort()
            ->values()
            ->all();

        self::assertSame([
            'clients_email_trgm_idx',
            'clients_full_name_trgm_idx',
        ], $indexNames);

        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        app(OrganizationContext::class)->set($organization);
        $client = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Ivan Petrov',
            'email' => 'ivan.petrov@example.test',
        ]);

        self::assertSame(
            [$client->id],
            app(ClientSearch::class)->query($admin, 'PETROV')->pluck('id')->all(),
        );
    }

    public function test_specialist_staff_link_composite_foreign_key_rejects_cross_organization_user(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $otherStaff = User::factory()->forOrganization($otherOrganization)->create();

        $this->expectException(QueryException::class);

        DB::table('specialists')->insert([
            'organization_id' => $organization->id,
            'display_name' => 'Cross organization specialist',
            'is_active' => true,
            'staff_user_id' => $otherStaff->id,
            'timezone' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_content_sections_allow_multiple_same_locale_records_per_scope(): void
    {
        $organization = Organization::factory()->create();

        $later = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'sort_order' => 20,
        ]);
        $earlier = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'sort_order' => 10,
        ]);

        self::assertNotSame($later->id, $earlier->id);
        self::assertSame(2, DB::table('content_sections')
            ->where('organization_id', $organization->id)
            ->where('section_key', 'author')
            ->where('locale', 'ru')
            ->count());
    }

    public function test_restriction_actor_composite_foreign_key_rejects_cross_organization_user(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();

        $this->expectException(QueryException::class);

        DB::table('client_booking_restrictions')->insert([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'blocked_by_user_id' => $otherAdmin->id,
            'reason' => 'Cross organization actor',
            'blocked_at' => now(),
            'unblocked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_service_name_uniqueness_is_scoped_and_catalog_type_is_database_constrained(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        Service::factory()->forOrganization($organization)->create(['name' => 'Same catalog name']);
        Service::factory()->forOrganization($otherOrganization)->create(['name' => 'Same catalog name']);

        $this->expectException(QueryException::class);
        Service::factory()->forOrganization($organization)->create(['name' => 'Same catalog name']);
    }

    public function test_specialist_names_are_not_required_to_be_unique(): void
    {
        $organization = Organization::factory()->create();

        $first = Specialist::factory()->forOrganization($organization)->create(['display_name' => 'Shared name']);
        $second = Specialist::factory()->forOrganization($organization)->create(['display_name' => 'Shared name']);

        self::assertNotSame($first->id, $second->id);
    }

    public function test_service_catalog_check_rejects_zero_duration(): void
    {
        $organization = Organization::factory()->create();
        $this->expectException(QueryException::class);

        DB::table('services')->insert([
            'organization_id' => $organization->id,
            'name' => 'Zero duration item',
            'summary' => 'Invalid test record',
            'is_active' => true,
            'catalog_type' => 'service',
            'duration_minutes' => 0,
            'buffer_minutes' => 0,
            'formats' => json_encode(['office'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_postgresql_catalog_timing_upper_boundary_persists(): void
    {
        $organization = Organization::factory()->create();
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 65535,
            'buffer_minutes' => 65535,
        ]);

        self::assertSame(65535, $service->fresh()->duration_minutes);
        self::assertSame(65535, $service->fresh()->buffer_minutes);
    }

    public function test_service_catalog_check_rejects_out_of_range_timing(): void
    {
        $organization = Organization::factory()->create();

        $this->expectException(QueryException::class);

        Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 65536,
        ]);
    }

    public function test_service_catalog_check_rejects_negative_buffer(): void
    {
        $organization = Organization::factory()->create();

        $this->expectException(QueryException::class);

        Service::factory()->forOrganization($organization)->create([
            'buffer_minutes' => -1,
        ]);
    }

    public function test_service_catalog_check_rejects_negative_price(): void
    {
        $organization = Organization::factory()->create();

        $this->expectException(QueryException::class);

        Service::factory()->forOrganization($organization)->create([
            'price_minor' => -1,
            'price_currency' => 'USD',
        ]);
    }

    public function test_content_section_check_rejects_negative_order(): void
    {
        $organization = Organization::factory()->create();

        $this->expectException(QueryException::class);

        ContentSection::factory()->forOrganization($organization)->create([
            'sort_order' => -1,
        ]);
    }

    public function test_service_catalog_check_rejects_mismatched_money_pair(): void
    {
        $organization = Organization::factory()->create();
        $this->expectException(QueryException::class);

        DB::table('services')->insert([
            'organization_id' => $organization->id,
            'name' => 'Mismatched money item',
            'summary' => 'Invalid test record',
            'is_active' => true,
            'catalog_type' => 'service',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'formats' => json_encode(['office'], JSON_THROW_ON_ERROR),
            'price_minor' => 1000,
            'price_currency' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_service_catalog_check_rejects_currency_without_price(): void
    {
        $organization = Organization::factory()->create();

        $this->expectException(QueryException::class);

        Service::factory()->forOrganization($organization)->create([
            'price_minor' => null,
            'price_currency' => 'USD',
        ]);
    }

    public function test_zero_price_buffer_and_content_order_are_valid_boundaries(): void
    {
        $organization = Organization::factory()->create();
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 1,
            'buffer_minutes' => 0,
            'price_minor' => 0,
            'price_currency' => 'USD',
        ]);
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'sort_order' => 0,
        ]);

        self::assertSame(0, $service->fresh()->buffer_minutes);
        self::assertSame(0, $service->fresh()->price_minor);
        self::assertSame(0, $section->fresh()->sort_order);
    }

    public function test_active_client_restriction_is_unique(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $restriction = ClientBookingRestriction::factory()
            ->forClient($client)
            ->blockedBy($admin)
            ->create();

        $this->expectException(QueryException::class);
        ClientBookingRestriction::factory()
            ->forClient($client)
            ->blockedBy($admin)
            ->create();
    }

    public function test_client_restriction_history_can_be_reopened_after_unblocking(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $restriction = ClientBookingRestriction::factory()
            ->forClient($client)
            ->blockedBy($admin)
            ->create();

        $restriction->forceFill([
            'unblocked_by_user_id' => $admin->id,
            'unblocked_at' => now(),
        ])->save();

        $second = ClientBookingRestriction::factory()
            ->forClient($client)
            ->blockedBy($admin)
            ->create();
        self::assertNotSame($restriction->id, $second->id);
    }

    public function test_service_price_is_stored_as_an_integer_minor_unit_value(): void
    {
        $organization = Organization::factory()->create();
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => 123456789,
            'price_currency' => 'USD',
        ]);

        self::assertSame(123456789, $service->fresh()->price_minor);
        self::assertIsInt(DB::table('services')->where('id', $service->id)->value('price_minor'));
    }
}
