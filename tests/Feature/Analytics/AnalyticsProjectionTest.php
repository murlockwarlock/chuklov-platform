<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Analytics\Application\AcquisitionAnalytics;
use App\Modules\Analytics\Application\AiFailureAnalytics;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\FinanceAnalytics;
use App\Modules\Analytics\Application\KnowledgeIngestionAnalytics;
use App\Modules\Analytics\Application\SchedulingAnalytics;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AnalyticsProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_reconcile_to_source_records_and_ignore_another_organization(): void
    {
        $now = CarbonImmutable::parse('2026-08-27 12:00:00', 'UTC');
        [$organization, $admin] = $this->organizationWithAdmin('Asia/Almaty');
        [$otherOrganization, $otherAdmin] = $this->organizationWithAdmin('UTC');
        $period = $this->customPeriod($organization, '2026-08-01', '2026-08-27', $now);

        $clientWithSource = $this->client($organization, '2026-08-01 10:00:00');
        $clientWithReferral = $this->client($organization, '2026-08-10 10:00:00');
        $clientWithoutSource = $this->client($organization, '2026-08-20 10:00:00');
        $this->attribution($clientWithSource, 'source', source: 'social');
        $this->attribution($clientWithReferral, 'referral', referralCode: 'ReferralCode123456');

        $cancelled = $this->booking($clientWithSource, '2026-08-03 10:00:00', BookingStatus::Cancelled);
        $this->bookingEvent($cancelled, BookingEventType::Cancelled, '2026-08-04 08:00:00');
        $rescheduled = $this->booking($clientWithSource, '2026-08-05 10:00:00');
        $this->bookingEvent($rescheduled, BookingEventType::Rescheduled, '2026-08-06 08:00:00');
        $completed = $this->booking($clientWithReferral, '2026-08-07 10:00:00', BookingStatus::Completed);
        $this->bookingEvent($completed, BookingEventType::Completed, '2026-08-08 08:00:00');
        $homeRequest = $this->booking(
            $clientWithoutSource,
            '2026-08-09 10:00:00',
            BookingStatus::PendingReview,
            VisitFormat::HomeVisit,
        );
        $nonRetainedVisit = $this->booking($clientWithoutSource, '2026-08-10 10:00:00', BookingStatus::Completed);
        $this->bookingEvent($nonRetainedVisit, BookingEventType::Completed, '2026-08-11 08:00:00');
        $noShow = $this->booking($clientWithoutSource, '2026-08-12 10:00:00', BookingStatus::NoShow);
        $this->bookingEvent($noShow, BookingEventType::NoShow, '2026-08-13 08:00:00');
        $futureBooking = $this->booking(
            $clientWithReferral,
            '2026-07-20 10:00:00',
            BookingStatus::Confirmed,
            VisitFormat::Office,
            $now->addDays(3),
        );

        $this->configureFinance($organization, true);
        $firstObligation = $this->obligation($organization, $clientWithSource, $cancelled, 10000, 'USD', '2026-08-02 10:00:00', 'a-first');
        $firstPayment = $this->ledger($firstObligation, 4000, 'USD', '2026-08-10 10:00:00', 'a-first-payment');
        $secondPayment = $this->ledger($firstObligation, 3000, 'USD', '2026-08-15 10:00:00', 'a-second-payment');
        $this->ledger($firstObligation, -1000, 'USD', '2026-08-16 10:00:00', 'a-first-correction', $secondPayment);
        $secondObligation = $this->obligation($organization, $clientWithReferral, $completed, 22000, 'EUR', '2026-08-20 10:00:00', 'a-second');
        $this->ledger($secondObligation, 5000, 'EUR', '2026-08-21 10:00:00', 'a-foreign-payment', baseAmountMinor: 5500);
        $this->ledger($secondObligation, 5000, 'EUR', '2026-09-01 10:00:00', 'a-future-payment', baseAmountMinor: 5500);

        $this->aiRun($organization, 'failed', '2026-08-11 10:00:00');
        $this->aiRun($organization, 'timed_out', '2026-08-12 10:00:00');
        $this->aiRun($organization, 'succeeded', '2026-08-13 10:00:00');
        $this->aiRun($organization, 'failed', '2026-07-31 10:00:00');
        $this->knowledgeRun($organization, 'failed', '2026-08-14 10:00:00');

        $otherClient = $this->client($otherOrganization, '2026-08-05 10:00:00');
        $this->attribution($otherClient, 'source', source: 'other-org');
        $otherBooking = $this->booking($otherClient, '2026-08-05 10:00:00', BookingStatus::Completed);
        $this->bookingEvent($otherBooking, BookingEventType::Completed, '2026-08-06 08:00:00');
        $this->configureFinance($otherOrganization);
        $otherObligation = $this->obligation($otherOrganization, $otherClient, $otherBooking, 99000, 'USD', '2026-08-10 10:00:00', 'b-only');
        $this->ledger($otherObligation, 99000, 'USD', '2026-08-11 10:00:00', 'b-payment');
        $this->aiRun($otherOrganization, 'failed', '2026-08-11 10:00:00');
        $this->knowledgeRun($otherOrganization, 'failed', '2026-08-11 10:00:00');

        app(OrganizationContext::class)->set($organization);

        $acquisition = app(AcquisitionAnalytics::class)->handle($admin, $period);
        $scheduling = app(SchedulingAnalytics::class)->handle($admin, $period);
        $finance = app(FinanceAnalytics::class)->handle($admin, $period);
        $aiFailures = app(AiFailureAnalytics::class)->handle($admin, $period);
        $ingestionFailures = app(KnowledgeIngestionAnalytics::class)->handle($admin, $period);

        self::assertSame(3, $acquisition->newClients);
        $sourceCounts = collect($acquisition->sources)->mapWithKeys(fn ($source): array => [$source->label => $source->count]);
        self::assertSame(1, $sourceCounts->get('social'));
        self::assertSame(1, $sourceCounts->get('Реферальный переход'));
        self::assertSame(1, $sourceCounts->get('Не указан'));
        self::assertSame(6, $scheduling->bookings);
        self::assertSame(1, $scheduling->cancellations);
        self::assertSame(1, $scheduling->reschedules);
        self::assertSame(2, $scheduling->visits);
        self::assertSame(1, $scheduling->homeRequests);
        self::assertSame(1, $scheduling->retainedClients);
        self::assertSame(1, $scheduling->notRetainedClients);
        self::assertSame(50.0, $scheduling->retentionRate());
        self::assertTrue($finance->available);
        self::assertSame('USD', $finance->baseCurrency);
        self::assertSame('11500', $finance->revenueMinor);
        self::assertSame('4167', $finance->averageReceiptMinor);
        self::assertSame('3833', $finance->realizedLtvMinor);
        self::assertSame('20500', $finance->debtMinor);
        self::assertSame(3, $finance->receiptCount);
        self::assertSame(3, $finance->cohortClientCount);
        self::assertSame(2, $aiFailures);
        self::assertSame(1, $ingestionFailures);
        self::assertNotSame($otherAdmin->getKey(), $admin->getKey());
        self::assertSame(1, Booking::query()->where('organization_id', $otherOrganization->getKey())->count());
        self::assertSame(1, FinancialLedgerEntry::query()->where('organization_id', $otherOrganization->getKey())->count());
        self::assertSame(1, AiRun::query()->where('organization_id', $otherOrganization->getKey())->count());
        self::assertSame(1, KnowledgeIngestionRun::query()->where('organization_id', $otherOrganization->getKey())->count());
        self::assertSame($homeRequest->getKey(), Booking::query()->whereKey($homeRequest->getKey())->value('id'));
        self::assertSame($futureBooking->getKey(), Booking::query()->whereKey($futureBooking->getKey())->value('id'));
        self::assertSame($firstPayment->getKey(), FinancialLedgerEntry::query()->whereKey($firstPayment->getKey())->value('id'));
    }

    public function test_period_boundary_uses_organization_timezone_and_half_open_range(): void
    {
        $now = CarbonImmutable::parse('2026-08-27 12:00:00', 'UTC');
        [$organization, $admin] = $this->organizationWithAdmin('Asia/Almaty');
        $period = $this->customPeriod($organization, '2026-08-01', '2026-08-01', $now);
        $atStart = $this->client($organization, '2026-08-01 00:00:00', 'Asia/Almaty');
        $atEnd = $this->client($organization, '2026-08-02 00:00:00', 'Asia/Almaty');

        app(OrganizationContext::class)->set($organization);
        $result = app(AcquisitionAnalytics::class)->handle($admin, $period);

        self::assertSame('2026-07-31T19:00:00+00:00', $period->startUtc->toIso8601String());
        self::assertSame('2026-08-01T19:00:00+00:00', $period->endUtc->toIso8601String());
        self::assertSame($atStart->getKey(), Client::query()->whereKey($atStart->getKey())->value('id'));
        self::assertSame($atEnd->getKey(), Client::query()->whereKey($atEnd->getKey())->value('id'));
        self::assertSame(1, $result->newClients);
    }

    public function test_source_buckets_are_bounded_and_ignore_legacy_client_source(): void
    {
        $now = CarbonImmutable::parse('2026-08-27 12:00:00', 'UTC');
        [$organization, $admin] = $this->organizationWithAdmin('UTC');

        for ($index = 1; $index <= 10; $index++) {
            $client = $this->client($organization, '2026-08-10 10:00:00');
            $client->forceFill(['lead_source' => 'legacy-only'])->save();
            $this->attribution(
                $client,
                $index === 1 ? 'legacy' : 'source',
                source: $index === 1 ? 'adopted-source' : 'source-'.$index,
            );
        }

        app(OrganizationContext::class)->set($organization);
        $result = app(AcquisitionAnalytics::class)->handle($admin, $this->customPeriod($organization, '2026-08-01', '2026-08-27', $now));

        self::assertSame(10, $result->newClients);
        self::assertCount(9, $result->sources);
        self::assertSame(2, collect($result->sources)->firstWhere('label', 'Другие')->count);
        self::assertContains('adopted-source', collect($result->sources)->pluck('label')->all());
        self::assertNotContains('legacy-only', collect($result->sources)->pluck('label')->all());
    }

    public function test_low_count_unknown_source_remains_explicit_when_known_sources_overflow(): void
    {
        $now = CarbonImmutable::parse('2026-08-27 12:00:00', 'UTC');
        [$organization, $admin] = $this->organizationWithAdmin('UTC');
        $knownSources = [];

        for ($sourceIndex = 1; $sourceIndex <= 9; $sourceIndex++) {
            $source = sprintf('known-source-%02d', $sourceIndex);
            $knownSources[] = $source;

            for ($clientIndex = 1; $clientIndex <= 2; $clientIndex++) {
                $client = $this->client($organization, '2026-08-10 10:00:00');
                $client->forceFill(['lead_source' => 'legacy-only'])->save();
                $this->attribution($client, 'source', source: $source);
            }
        }

        $unattributedClient = $this->client($organization, '2026-08-10 10:00:00');
        $unattributedClient->forceFill(['lead_source' => 'legacy-only'])->save();

        app(OrganizationContext::class)->set($organization);
        $result = app(AcquisitionAnalytics::class)->handle($admin, $this->customPeriod($organization, '2026-08-01', '2026-08-27', $now));
        $sources = collect($result->sources);
        $sourceCounts = $sources->mapWithKeys(fn ($source): array => [$source->label => $source->count]);
        $labels = $sources->pluck('label')->all();

        self::assertSame(19, $result->newClients);
        self::assertContains('Не указан', $labels);
        self::assertSame(1, $sourceCounts->get('Не указан'));
        self::assertContains('Другие', $labels);
        self::assertSame(2, $sourceCounts->get('Другие'));
        self::assertSame(['known-source-09'], array_values(array_diff($knownSources, $labels)));
        self::assertSame(19, $sources->sum('count'));
        self::assertCount(10, $result->sources);
        self::assertNotContains('legacy-only', $labels);
    }

    public function test_known_source_literal_unknown_label_remains_distinct_from_unattributed_clients(): void
    {
        $now = CarbonImmutable::parse('2026-08-27 12:00:00', 'UTC');
        [$organization, $admin] = $this->organizationWithAdmin('UTC');
        $ordinarySources = [];

        for ($sourceIndex = 1; $sourceIndex <= 9; $sourceIndex++) {
            $source = sprintf('ordinary-source-%02d', $sourceIndex);
            $ordinarySources[] = $source;
            $client = $this->client($organization, '2026-08-10 10:00:00');
            $client->forceFill(['lead_source' => 'legacy-only'])->save();
            $this->attribution($client, 'source', source: $source);
        }

        for ($clientIndex = 1; $clientIndex <= 2; $clientIndex++) {
            $client = $this->client($organization, '2026-08-10 10:00:00');
            $client->forceFill(['lead_source' => 'legacy-only'])->save();
            $this->attribution($client, 'referral', referralCode: 'ReferralCode123456');
        }

        for ($clientIndex = 1; $clientIndex <= 2; $clientIndex++) {
            $client = $this->client($organization, '2026-08-10 10:00:00');
            $client->forceFill(['lead_source' => 'legacy-only'])->save();
            $this->attribution($client, 'source', source: 'Реферальный переход');
        }

        for ($clientIndex = 1; $clientIndex <= 2; $clientIndex++) {
            $client = $this->client($organization, '2026-08-10 10:00:00');
            $client->forceFill(['lead_source' => 'legacy-only'])->save();
            $this->attribution($client, 'utm', utmSource: 'facebook');
        }

        for ($clientIndex = 1; $clientIndex <= 2; $clientIndex++) {
            $client = $this->client($organization, '2026-08-10 10:00:00');
            $client->forceFill(['lead_source' => 'legacy-only'])->save();
            $this->attribution($client, 'source', source: 'UTM: facebook');
        }

        for ($clientIndex = 1; $clientIndex <= 2; $clientIndex++) {
            $client = $this->client($organization, '2026-08-10 10:00:00');
            $client->forceFill(['lead_source' => 'legacy-only'])->save();
            $this->attribution($client, 'source', source: 'Не указан');
        }

        for ($clientIndex = 1; $clientIndex <= 2; $clientIndex++) {
            $client = $this->client($organization, '2026-08-10 10:00:00');
            $client->forceFill(['lead_source' => 'legacy-only'])->save();
            $this->attribution($client, 'source', source: 'Источник: Не указан');
        }

        for ($clientIndex = 1; $clientIndex <= 2; $clientIndex++) {
            $client = $this->client($organization, '2026-08-10 10:00:00');
            $client->forceFill(['lead_source' => 'legacy-only'])->save();
            $this->attribution($client, 'source', source: 'Другие');
        }

        $unattributedClient = $this->client($organization, '2026-08-10 10:00:00');
        $unattributedClient->forceFill(['lead_source' => 'legacy-only'])->save();

        app(OrganizationContext::class)->set($organization);
        $result = app(AcquisitionAnalytics::class)->handle($admin, $this->customPeriod($organization, '2026-08-01', '2026-08-27', $now));
        $sources = collect($result->sources);
        $sourceCounts = $sources->mapWithKeys(fn ($source): array => [$source->label => $source->count]);
        $labels = $sources->pluck('label')->all();

        self::assertSame(24, $result->newClients);
        self::assertSame(2, $sourceCounts->get('Реферальный переход'));
        self::assertSame(2, $sourceCounts->get('Источник: Реферальный переход'));
        self::assertSame(2, $sourceCounts->get('UTM: facebook'));
        self::assertSame(2, $sourceCounts->get('Источник: UTM: facebook'));
        self::assertSame(1, $sourceCounts->get('Не указан'));
        self::assertSame(2, $sourceCounts->get('Источник: Не указан'));
        self::assertSame(2, $sourceCounts->get('Источник: Источник: Не указан'));
        self::assertSame(2, $sourceCounts->get('Источник: Другие'));
        self::assertSame(8, $sourceCounts->get('Другие'));
        self::assertSame(['ordinary-source-02', 'ordinary-source-03', 'ordinary-source-04', 'ordinary-source-05', 'ordinary-source-06', 'ordinary-source-07', 'ordinary-source-08', 'ordinary-source-09'], array_values(array_diff($ordinarySources, $labels)));
        self::assertSame(24, $sources->sum('count'));
        self::assertSame($result->newClients, $sources->sum('count'));
        self::assertCount(10, $result->sources);
        self::assertSame(count($labels), count(array_unique($labels)));
        self::assertSame(1, $sources->where('label', 'Не указан')->count());
        self::assertSame(1, $sources->where('label', 'Другие')->count());
        self::assertNotContains('legacy-only', $labels);
    }

    public function test_invalid_finance_reconciliation_fails_closed_without_a_partial_total(): void
    {
        $now = CarbonImmutable::parse('2026-08-27 12:00:00', 'UTC');
        [$organization, $admin] = $this->organizationWithAdmin('UTC');
        $client = $this->client($organization, '2026-08-10 10:00:00');
        $booking = $this->booking($client, '2026-08-10 10:00:00');
        $this->configureFinance($organization);
        $obligation = $this->obligation($organization, $client, $booking, 10000, 'USD', '2026-08-10 10:00:00', 'invalid');
        $this->ledger($obligation, 2000, 'USD', '2026-08-11 10:00:00', 'invalid-payment');
        DB::table('financial_obligations')->where('id', $obligation->getKey())->update([
            'conversion_snapshots' => json_encode(['base' => []], JSON_THROW_ON_ERROR),
        ]);

        app(OrganizationContext::class)->set($organization);
        $result = app(FinanceAnalytics::class)->handle($admin, $this->customPeriod($organization, '2026-08-01', '2026-08-27', $now));

        self::assertFalse($result->available);
        self::assertNull($result->revenueMinor);
        self::assertNull($result->averageReceiptMinor);
        self::assertNull($result->realizedLtvMinor);
        self::assertNull($result->debtMinor);
    }

    public function test_empty_state_is_zero_or_no_value_and_requires_no_finance_configuration(): void
    {
        $now = CarbonImmutable::parse('2026-08-27 12:00:00', 'UTC');
        [$organization, $admin] = $this->organizationWithAdmin('UTC');
        $period = $this->customPeriod($organization, '2026-08-01', '2026-08-27', $now);
        app(OrganizationContext::class)->set($organization);

        $acquisition = app(AcquisitionAnalytics::class)->handle($admin, $period);
        $scheduling = app(SchedulingAnalytics::class)->handle($admin, $period);
        $finance = app(FinanceAnalytics::class)->handle($admin, $period);

        self::assertSame(0, $acquisition->newClients);
        self::assertSame([], $acquisition->sources);
        self::assertSame(0, $scheduling->bookings);
        self::assertSame(0, $scheduling->visits);
        self::assertNull($scheduling->retentionRate());
        self::assertFalse($finance->available);
        self::assertNull($finance->averageReceiptMinor);
    }

    public function test_aggregate_query_count_is_bounded_when_client_count_grows(): void
    {
        $now = CarbonImmutable::parse('2026-08-27 12:00:00', 'UTC');
        [$organization, $admin] = $this->organizationWithAdmin('UTC');
        $this->configureFinance($organization);
        $period = $this->customPeriod($organization, '2026-08-01', '2026-08-27', $now);
        app(OrganizationContext::class)->set($organization);

        $measure = function () use ($admin, $period): int {
            $queryCount = 0;
            $listening = true;
            DB::listen(static function () use (&$queryCount, &$listening): void {
                if ($listening) {
                    $queryCount++;
                }
            });

            app(AcquisitionAnalytics::class)->handle($admin, $period);
            app(SchedulingAnalytics::class)->handle($admin, $period);
            app(FinanceAnalytics::class)->handle($admin, $period);
            app(AiFailureAnalytics::class)->handle($admin, $period);
            app(KnowledgeIngestionAnalytics::class)->handle($admin, $period);
            $listening = false;

            return $queryCount;
        };

        $before = $measure();
        Client::factory()->forOrganization($organization)->count(25);
        $after = $measure();

        self::assertSame($before, $after);
        self::assertLessThanOrEqual(24, $after);
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(string $timezone): array
    {
        $organization = Organization::factory()->create(['timezone' => $timezone]);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();

        return [$organization, $admin];
    }

    private function customPeriod(Organization $organization, string $start, string $end, CarbonImmutable $now): DashboardPeriod
    {
        return DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => $start, 'end_date' => $end],
            $organization->defaultTimezone(),
            $now,
        );
    }

    private function client(Organization $organization, string $createdAt, string $timezone = 'UTC'): Client
    {
        $timestamp = CarbonImmutable::parse($createdAt, $timezone)->utc();
        $client = Client::factory()->forOrganization($organization)->create();
        DB::table('clients')->where('id', $client->getKey())->update([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $client->refresh();
    }

    private function attribution(Client $client, string $sourceType, ?string $source = null, ?string $referralCode = null, ?string $utmSource = null): ClientAttribution
    {
        $attribution = new ClientAttribution;
        $attribution->forceFill([
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
            'source_type' => $sourceType,
            'source' => $source,
            'referral_code' => $referralCode,
            'utm_source' => $utmSource,
            'utm_medium' => null,
            'utm_campaign' => null,
            'utm_content' => null,
            'utm_term' => null,
            'capture_channel' => 'portal',
            'capture_context' => 'test',
            'captured_at' => $client->created_at,
            'accepted_at' => $client->created_at,
        ])->save();

        return $attribution;
    }

    private function booking(
        Client $client,
        string $createdAt,
        BookingStatus $status = BookingStatus::Confirmed,
        VisitFormat $visitFormat = VisitFormat::Office,
        ?CarbonImmutable $startsAt = null,
    ): Booking {
        $created = CarbonImmutable::parse($createdAt, 'UTC');
        $startsAt ??= $created->addDay();
        $specialist = Specialist::factory()->forOrganization($client->organization)->create();
        $service = Service::factory()->forOrganization($client->organization)->create();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => $status,
                'visit_format' => $visitFormat,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addHour(),
                'blocking_ends_at' => $startsAt->addHour(),
            ]);
        DB::table('bookings')->where('id', $booking->getKey())->update([
            'created_at' => $created,
            'updated_at' => $created,
        ]);

        return $booking->refresh();
    }

    private function bookingEvent(Booking $booking, BookingEventType $type, string $occurredAt): BookingEvent
    {
        return BookingEvent::factory()->forBooking($booking)->create([
            'event_type' => $type->value,
            'occurred_at' => CarbonImmutable::parse($occurredAt, 'UTC'),
        ]);
    }

    private function configureFinance(Organization $organization, bool $withEuro = false): void
    {
        $timestamp = now();
        DB::table('organization_currency_configurations')->insert([
            'organization_id' => $organization->getKey(),
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'force_single_currency' => ! $withEuro,
            'rounding_mode' => FinancialRoundingMode::HalfUp->value,
            'version' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('organization_allowed_currencies')->insert([
            'organization_id' => $organization->getKey(),
            'currency' => 'USD',
            'created_at' => $timestamp,
        ]);

        if (! $withEuro) {
            return;
        }

        DB::table('organization_allowed_currencies')->insert([
            'organization_id' => $organization->getKey(),
            'currency' => 'EUR',
            'created_at' => $timestamp,
        ]);
        DB::table('organization_exchange_rates')->insert([
            [
                'organization_id' => $organization->getKey(),
                'source_currency' => 'EUR',
                'target_currency' => 'USD',
                'rate' => '1.1',
                'version' => 1,
                'effective_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'organization_id' => $organization->getKey(),
                'source_currency' => 'USD',
                'target_currency' => 'EUR',
                'rate' => '0.9090909090909091',
                'version' => 1,
                'effective_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);
    }

    private function obligation(
        Organization $organization,
        Client $client,
        Booking $booking,
        int $baseAmountMinor,
        string $settlementCurrency,
        string $createdAt,
        string $key,
    ): FinancialObligation {
        $settlementAmountMinor = $settlementCurrency === 'EUR' ? 20000 : $baseAmountMinor;
        $settlementMoney = Money::ofMinor($settlementAmountMinor, $settlementCurrency);
        $baseMoney = Money::ofMinor($baseAmountMinor, 'USD');
        $snapshot = [
            'source_amount_minor' => (string) $settlementAmountMinor,
            'source_currency' => $settlementCurrency,
            'target_amount_minor' => (string) $baseAmountMinor,
            'target_currency' => 'USD',
            'rate' => $settlementCurrency === 'EUR' ? '1.1' : '1',
            'rate_id' => null,
            'rate_version' => null,
            'effective_at' => null,
            'rounding_mode' => FinancialRoundingMode::HalfUp->value,
            'source_scale' => $settlementMoney->scale(),
            'target_scale' => $baseMoney->scale(),
        ];
        $obligation = new FinancialObligation;
        $obligation->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'booking_id' => $booking->getKey(),
            'service_id' => $booking->service_id,
            'amount_minor' => $settlementAmountMinor,
            'currency' => $settlementCurrency,
            'base_amount_minor' => $baseAmountMinor,
            'base_currency' => 'USD',
            'display_amount_minor' => $baseAmountMinor,
            'display_currency' => 'USD',
            'payment_amount_minor' => $settlementAmountMinor,
            'payment_currency' => $settlementCurrency,
            'settlement_amount_minor' => $settlementAmountMinor,
            'settlement_currency' => $settlementCurrency,
            'price_snapshot' => ['amount_minor' => (string) $settlementAmountMinor],
            'conversion_snapshots' => ['base' => $snapshot, 'display' => $snapshot],
            'creation_key' => $key,
            'created_at' => CarbonImmutable::parse($createdAt, 'UTC'),
            'updated_at' => CarbonImmutable::parse($createdAt, 'UTC'),
        ])->save();

        return $obligation->refresh();
    }

    private function ledger(
        FinancialObligation $obligation,
        int $amountMinor,
        string $currency,
        string $occurredAt,
        string $key,
        ?FinancialLedgerEntry $corrects = null,
        ?int $baseAmountMinor = null,
    ): FinancialLedgerEntry {
        $baseAmountMinor ??= $amountMinor;
        $entry = new FinancialLedgerEntry;
        $entry->forceFill([
            'organization_id' => $obligation->organization_id,
            'obligation_id' => $obligation->getKey(),
            'entry_type' => $corrects === null ? 'manual_payment' : 'correction',
            'source' => 'crm',
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'payment_amount_minor' => $amountMinor,
            'payment_currency' => $currency,
            'base_amount_minor' => $baseAmountMinor,
            'base_currency' => 'USD',
            'display_amount_minor' => $baseAmountMinor,
            'display_currency' => 'USD',
            'settlement_amount_minor' => $amountMinor,
            'settlement_currency' => $currency,
            'conversion_snapshot' => null,
            'payment_method' => 'cash',
            'occurred_at' => CarbonImmutable::parse($occurredAt, 'UTC'),
            'note' => null,
            'actor_user_id' => null,
            'provider_reference' => null,
            'idempotency_key' => $key,
            'corrects_ledger_entry_id' => $corrects?->getKey(),
        ])->save();

        return $entry->refresh();
    }

    private function aiRun(Organization $organization, string $status, string $finishedAt): AiRun
    {
        return AiRun::create([
            'organization_id' => $organization->getKey(),
            'capability' => 'general_assistant',
            'workflow_key' => 'analytics-test',
            'status' => $status,
            'execution_mode' => 'sync',
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
            'error_category' => $status === 'succeeded' ? null : 'internal_error',
            'finished_at' => CarbonImmutable::parse($finishedAt, 'UTC'),
        ]);
    }

    private function knowledgeRun(Organization $organization, string $status, string $completedAt): KnowledgeIngestionRun
    {
        $source = KnowledgeSource::create([
            'organization_id' => $organization->getKey(),
            'type' => 'authored_text',
            'title' => 'Analytics fixture',
            'status' => 'active',
        ]);
        $revision = KnowledgeRevision::create([
            'organization_id' => $organization->getKey(),
            'knowledge_source_id' => $source->getKey(),
            'version' => 1,
            'status' => $status,
            'content' => 'fixture',
            'mime_type' => 'text/plain',
            'size_bytes' => 7,
            'content_checksum' => hash('sha256', 'fixture'),
        ]);

        return KnowledgeIngestionRun::create([
            'organization_id' => $organization->getKey(),
            'knowledge_source_id' => $source->getKey(),
            'knowledge_revision_id' => $revision->getKey(),
            'configuration_key' => hash('sha256', $organization->getKey().$completedAt),
            'status' => $status,
            'chunk_strategy' => 'fixed',
            'chunk_version' => 'v1',
            'chunk_target_characters' => 128,
            'chunk_maximum_characters' => 256,
            'chunk_overlap_characters' => 0,
            'embedding_provider' => 'test',
            'embedding_model' => 'test',
            'embedding_dimensions' => (int) config('rag.embedding.dimensions'),
            'embedding_configuration_version' => 'v1',
            'attempts' => 2,
            'completed_at' => CarbonImmutable::parse($completedAt, 'UTC'),
        ]);
    }
}
