<?php

namespace Tests\Integration;

use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Exceptions\AiBudgetExceededException;
use App\Modules\AI\Domain\Models\AiOrganizationDailyBudget;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MilestoneTenConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_concurrent_budget_reservations_respect_maximum_limit(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Budget reservation concurrency requires PostgreSQL row locks.');
        }

        $organization = Organization::factory()->create();
        AiOrganizationSafetyControl::query()->create([
            'organization_id' => $organization->id,
            'max_daily_spend_minor_units' => 100,
        ]);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::reserve($organization->id, 80),
            static fn (): string => self::reserve($organization->id, 80),
        ]);

        // One must succeed ('reserved') and the other must fail ('exceeded')
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'reserved')));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'exceeded')));

        $budget = AiOrganizationDailyBudget::query()
            ->where('organization_id', $organization->id)
            ->whereDate('usage_date', Carbon::now()->toDateString())
            ->first();

        self::assertNotNull($budget);
        self::assertLessThanOrEqual(100, $budget->spent_minor_units + $budget->reserved_minor_units);
        self::assertSame(80, (int) $budget->reserved_minor_units);
    }

    private static function reserve(int $organizationId, int $amount): string
    {
        try {
            app(AiSafetyBudgetManagerInterface::class)->reserveBudget($organizationId, $amount);

            return 'reserved';
        } catch (AiBudgetExceededException) {
            return 'exceeded';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception);
        }
    }
}
