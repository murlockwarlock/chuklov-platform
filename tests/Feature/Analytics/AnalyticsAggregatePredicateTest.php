<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\AcquisitionAnalytics;
use App\Modules\Analytics\Application\ClientCohortQuery;
use App\Modules\Analytics\Application\Data\AnalyticsAggregateDimension;
use App\Modules\Analytics\Application\Data\AnalyticsAggregatePredicate;
use App\Modules\Analytics\Application\Data\ClientCohortFilters;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Domain\Enums\ClientSegment;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Feedback\Domain\Models\FeedbackTheme;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Tracker\Domain\Enums\TrackerTaskFrequency;
use App\Modules\Tracker\Domain\Enums\TrackerTaskType;
use App\Modules\Tracker\Domain\Models\TrackerGoalTag;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AnalyticsAggregatePredicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_typed_predicates_use_exact_authoritative_dimension_values(): void
    {
        [$organization, $admin] = $this->fixture();
        $target = Client::factory()->forOrganization($organization)->create([
            'birth_date' => '1985-06-10',
            'gender' => 'MALE',
            'country' => 'KZ',
        ]);
        $target->forceFill(['archived_at' => '2026-10-01 10:00:00'])->save();
        $other = Client::factory()->forOrganization($organization)->create([
            'birth_date' => '2015-06-10',
            'gender' => 'FEMALE',
            'country' => 'RU',
        ]);
        $foreignOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        Client::factory()->forOrganization($foreignOrganization)->create([
            'birth_date' => '1985-06-10',
            'gender' => 'MALE',
            'country' => 'KZ',
        ]);
        $service = Service::factory()->forOrganization($organization)->create(['name' => 'Сервис exact']);
        $otherService = Service::factory()->forOrganization($organization)->create(['name' => 'Другой сервис']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['display_name' => 'Специалист exact']);
        $otherSpecialist = Specialist::factory()->forOrganization($organization)->create(['display_name' => 'Другой специалист']);
        $targetBooking = Booking::factory()->forClient($target)->forSpecialist($specialist)->forService($service)->create([
            'status' => 'completed',
            'starts_at' => '2026-10-10 10:00:00',
            'ends_at' => '2026-10-10 11:00:00',
            'blocking_ends_at' => '2026-10-10 11:00:00',
        ]);
        $otherBooking = Booking::factory()->forClient($other)->forSpecialist($otherSpecialist)->forService($otherService)->create([
            'status' => 'completed',
            'starts_at' => '2026-10-10 12:00:00',
            'ends_at' => '2026-10-10 13:00:00',
            'blocking_ends_at' => '2026-10-10 13:00:00',
        ]);
        BookingEvent::factory()->forBooking($targetBooking)->create([
            'event_type' => 'completed',
            'occurred_at' => '2026-10-10 10:30:00',
        ]);
        BookingEvent::factory()->forBooking($otherBooking)->create([
            'event_type' => 'cancelled',
            'occurred_at' => '2026-10-10 12:30:00',
        ]);

        $theme = FeedbackTheme::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'name' => 'Тема exact',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $outsideFeedbackClient = Client::factory()->forOrganization($organization)->create();
        $this->createFeedback($organization, $target, $theme, '2026-10-10 10:00:00', 'target-feedback');
        $this->createFeedback($organization, $outsideFeedbackClient, $theme, '2025-10-10 10:00:00', 'outside-feedback');

        $goal = TrackerGoalTag::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'name' => 'Цель exact',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $task = TrackerTask::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $target->getKey(),
            'title' => 'Задача exact',
            'task_type' => TrackerTaskType::Practice->value,
            'frequency' => TrackerTaskFrequency::Daily->value,
            'starts_on' => '2026-10-10',
            'active' => true,
            'display_order' => 1,
            'created_by_user_id' => $admin->getKey(),
            'created_at' => '2026-11-01 10:00:00',
            'updated_at' => '2026-11-01 10:00:00',
        ]);
        $task->goalTags()->syncWithPivotValues([$goal->getKey()], [
            'organization_id' => $organization->getKey(),
            'client_id' => $target->getKey(),
        ]);

        $purchaseId = DB::table('commerce_purchases')->insertGetId([
            'organization_id' => $organization->getKey(),
            'client_id' => $target->getKey(),
            'status' => 'paid',
            'total_amount_minor' => 1000,
            'currency' => 'KZT',
            'purchase_snapshot' => json_encode(['catalog_type' => 'service'], JSON_THROW_ON_ERROR),
            'created_at' => '2026-10-10 10:00:00',
            'updated_at' => '2026-10-10 10:00:00',
        ]);
        DB::table('commerce_purchase_items')->insert([
            'organization_id' => $organization->getKey(),
            'purchase_id' => $purchaseId,
            'sellable_type' => Service::class,
            'sellable_id' => $service->getKey(),
            'quantity' => 1,
            'amount_minor' => 1000,
            'currency' => 'KZT',
            'product_snapshot' => json_encode(['catalog_type' => 'service'], JSON_THROW_ON_ERROR),
            'created_at' => '2026-10-10 10:00:00',
            'updated_at' => '2026-10-10 10:00:00',
        ]);

        app(OrganizationContext::class)->set($organization);
        $period = $this->period();
        $predicates = [
            new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::FeedbackTheme, (int) $theme->getKey()),
            new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::GoalTag, (int) $goal->getKey()),
            new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::Service, (int) $service->getKey()),
            new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::Specialist, (int) $specialist->getKey()),
            new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::PurchaseGroup, 'service'),
            new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::BookingEvent, 'completed'),
            new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::Segment, ClientSegment::Archived->value),
            new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::AgeRange, 'adults_30_44', 30, 44),
            new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::Sex, 'MALE'),
            new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::Children, 'adult'),
            new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::Country, 'KZ'),
        ];

        foreach ($predicates as $predicate) {
            self::assertSame([$target->getKey()], $this->aggregateIds($admin, $predicate, $period), $predicate->dimension->value);
        }
    }

    public function test_demographic_missing_predicates_distinguish_null_empty_and_known_values(): void
    {
        [$organization, $admin] = $this->fixture();
        $nullClient = Client::factory()->forOrganization($organization)->create([
            'birth_date' => '2005-06-10',
            'gender' => null,
            'country' => null,
        ]);
        $emptyClient = Client::factory()->forOrganization($organization)->create([
            'birth_date' => '2005-06-10',
            'gender' => '',
            'country' => '',
        ]);
        $unknownAgeClient = Client::factory()->forOrganization($organization)->create([
            'birth_date' => null,
            'gender' => 'FEMALE',
            'country' => 'KZ',
        ]);
        $knownClient = Client::factory()->forOrganization($organization)->create([
            'birth_date' => '1985-06-10',
            'gender' => 'FEMALE',
            'country' => 'KZ',
        ]);
        app(OrganizationContext::class)->set($organization);
        $period = $this->period();

        self::assertSame(
            [$nullClient->getKey(), $emptyClient->getKey()],
            $this->aggregateIds($admin, new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::CountryUnknown, AnalyticsAggregatePredicate::UnknownValue), $period),
        );
        self::assertSame(
            [$nullClient->getKey(), $emptyClient->getKey()],
            $this->aggregateIds($admin, new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::SexUnknown, AnalyticsAggregatePredicate::UnknownValue), $period),
        );
        self::assertSame(
            [$unknownAgeClient->getKey()],
            $this->aggregateIds($admin, new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::AgeUnknown, AnalyticsAggregatePredicate::UnknownValue), $period),
        );
        self::assertSame(
            [$unknownAgeClient->getKey()],
            $this->aggregateIds($admin, new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::ChildrenUnknown, AnalyticsAggregatePredicate::UnknownValue), $period),
        );
        self::assertSame(
            [$knownClient->getKey()],
            $this->aggregateIds($admin, new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::AgeRange, 'adults_30_44', 30, 44), $period),
        );
    }

    public function test_unknown_source_predicate_matches_the_visible_unknown_bucket(): void
    {
        [$organization, $admin] = $this->fixture();
        $unknown = Client::factory()->forOrganization($organization)->create(['created_at' => '2026-10-05 10:00:00']);
        $known = Client::factory()->forOrganization($organization)->create(['created_at' => '2026-10-05 10:00:00']);
        $attribution = new ClientAttribution;
        $attribution->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $known->getKey(),
            'source_type' => 'source',
            'source' => 'instagram',
            'capture_channel' => 'test',
            'capture_context' => 'analytics',
            'captured_at' => '2026-10-05 10:00:00',
            'accepted_at' => '2026-10-05 10:00:00',
        ])->save();
        app(OrganizationContext::class)->set($organization);
        $period = $this->period();
        $sources = app(AcquisitionAnalytics::class)->handle($admin, $period)->sources;
        $unknownBucket = collect($sources)->firstWhere('kind', 'unknown');

        self::assertNotNull($unknownBucket);
        self::assertSame(1, $unknownBucket->count);
        self::assertSame([$unknown->getKey()], $this->aggregateIds(
            $admin,
            new AnalyticsAggregatePredicate(AnalyticsAggregateDimension::AcquisitionUnknown, AnalyticsAggregatePredicate::UnknownValue),
            $period,
        ));
    }

    /** @return list<int> */
    private function aggregateIds(User $admin, AnalyticsAggregatePredicate $predicate, DashboardPeriod $period): array
    {
        $query = app(ClientCohortQuery::class)->for($admin, new ClientCohortFilters, $period);
        app(ClientCohortQuery::class)->constrainAggregate($query, $predicate, $period);

        return $query->orderBy('clients.id')->pluck('clients.id')->map(static fn (int|string $id): int => (int) $id)->all();
    }

    private function createFeedback(Organization $organization, Client $client, FeedbackTheme $theme, string $submittedAt, string $idempotencyKey): void
    {
        $submission = new FeedbackSubmission;
        $submission->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'score' => 8,
            'source' => 'test',
            'idempotency_key' => $idempotencyKey,
            'request_hash' => hash('sha256', $idempotencyKey),
            'submitted_at' => $submittedAt,
        ])->save();
        $submission->themes()->syncWithPivotValues([$theme->getKey()], [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
        ]);
    }

    private function period(): DashboardPeriod
    {
        return DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );
    }

    /** @return array{0: Organization, 1: User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();

        return [$organization, $admin];
    }
}
