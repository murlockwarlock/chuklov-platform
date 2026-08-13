<?php

namespace Tests\Integration;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MilestoneFourConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_two_real_parallel_transactions_cannot_both_insert_the_same_specialist_interval(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The race test requires PostgreSQL exclusion constraints.');
        }

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $start = CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC');
        $attributes = [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'specialist_id' => $specialist->getKey(),
            'service_id' => $service->getKey(),
            'visit_format' => 'office',
            'status' => BookingStatus::Requested->value,
            'payment_status' => 'unpaid',
            'source' => 'portal',
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->addHour()->toIso8601String(),
            'blocking_ends_at' => $start->addMinutes(75)->toIso8601String(),
            'schedule_timezone' => 'UTC',
            'client_timezone' => 'UTC',
            'meeting_link_mode' => null,
            'meeting_url' => null,
            'party_size' => 1,
            'event_version' => 1,
            'requested_at' => now()->toIso8601String(),
            'cancelled_at' => null,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::insertCompetingBooking($attributes, 'parallel-a'),
            static fn (): string => self::insertCompetingBooking($attributes, 'parallel-b'),
        ]);

        self::assertNotContains('error', array_map(
            static fn (string $result): string => str_starts_with($result, 'error:') ? 'error' : $result,
            $results,
        ), implode(', ', $results));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'inserted')));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'conflict')));
        self::assertSame(1, Booking::query()->where('organization_id', $organization->getKey())->count());
    }

    /** @param array<string, mixed> $attributes */
    private static function insertCompetingBooking(array $attributes, string $uid): string
    {
        $attributes['calendar_uid'] = $uid;

        try {
            DB::transaction(function () use ($attributes): void {
                DB::table('bookings')->insert($attributes);
                DB::select('SELECT pg_sleep(1)');
            });

            return 'inserted';
        } catch (\Throwable $exception) {
            if ($exception instanceof QueryException
                && in_array($exception->getCode() ?: ($exception->errorInfo[0] ?? null), ['23P01', '40P01'], true)) {
                return 'conflict';
            }

            return 'error:'.get_class($exception).':'.(string) $exception->getCode().':'.$exception->getMessage();
        }
    }
}
