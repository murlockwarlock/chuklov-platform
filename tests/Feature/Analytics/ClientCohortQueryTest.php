<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\AcquisitionAnalytics;
use App\Modules\Analytics\Application\ClientCohortQuery;
use App\Modules\Analytics\Application\Data\ClientCohortFilters;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\DemographicsAnalytics;
use App\Modules\Analytics\Application\SaveAnalyticsClientGroup;
use App\Modules\Analytics\Application\ServiceVisitAnalytics;
use App\Modules\Analytics\Domain\Models\AnalyticsClientGroup;
use App\Modules\Analytics\Domain\Models\AnalyticsClientGroupVersion;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClientCohortQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cohort_is_organization_scoped_and_combines_filters_with_and_or_and_exclude(): void
    {
        [$organization, $admin] = $this->fixture();
        $matching = Client::factory()->forOrganization($organization)->create([
            'gender' => 'FEMALE',
            'birth_date' => '1985-06-10',
            'country' => 'KZ',
        ]);
        $otherGender = Client::factory()->forOrganization($organization)->create([
            'gender' => 'MALE',
            'birth_date' => '1985-06-10',
            'country' => 'KZ',
        ]);
        $otherCountry = Client::factory()->forOrganization($organization)->create([
            'gender' => 'FEMALE',
            'birth_date' => '1985-06-10',
            'country' => 'RU',
        ]);
        $foreignOrganization = Organization::factory()->create();
        $foreign = Client::factory()->forOrganization($foreignOrganization)->create([
            'gender' => 'FEMALE',
            'birth_date' => '1985-06-10',
            'country' => 'KZ',
        ]);

        ClientAttribution::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $matching->getKey(),
            'source_type' => 'source',
            'source' => 'Instagram',
            'capture_channel' => 'crm',
            'captured_at' => now(),
            'accepted_at' => now(),
        ]);
        ClientAttribution::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $otherGender->getKey(),
            'source_type' => 'source',
            'source' => 'Telegram',
            'capture_channel' => 'crm',
            'captured_at' => now(),
            'accepted_at' => now(),
        ]);

        app(OrganizationContext::class)->set($organization);
        $filters = new ClientCohortFilters(
            genders: ['FEMALE'],
            countries: ['KZ'],
            sourceKeys: ['Instagram'],
            ageMin: 30,
            ageMax: 44,
            excludeClientIds: [$otherCountry->getKey()],
        );

        $query = app(ClientCohortQuery::class)->for($admin, $filters);

        self::assertSame([$matching->getKey()], $query->pluck('clients.id')->all());
        self::assertNotContains($foreign->getKey(), $query->pluck('clients.id')->all());
        self::assertSame(1, app(ClientCohortQuery::class)->previewCount($admin, $filters));
    }

    public function test_six_client_owner_fixture_returns_one_exact_cohort_to_preview_and_dimensions(): void
    {
        [$organization, $admin] = $this->fixture();
        $service = Service::factory()->forOrganization($organization)->create(['name' => 'Комплексная терапия']);
        $service->forceFill(['category' => 'Терапия'])->save();
        $otherService = Service::factory()->forOrganization($organization)->create(['name' => 'Другой сервис', 'category' => 'Массаж']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['display_name' => 'Специалист один']);
        $secondSpecialist = Specialist::factory()->forOrganization($organization)->create(['display_name' => 'Специалист два']);
        $tag = MedicalProblemTag::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Боль в шее',
            'group_name' => 'Проблемы',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $clients = [
            'target' => Client::factory()->forOrganization($organization)->create([
                'full_name' => 'Когорта: целевой клиент',
                'gender' => 'FEMALE',
                'birth_date' => '1985-06-10',
                'country' => 'KZ',
                'created_at' => '2026-10-10 10:00:00',
                'updated_at' => '2026-10-10 10:00:00',
            ]),
            'without_tag' => Client::factory()->forOrganization($organization)->create([
                'gender' => 'FEMALE', 'birth_date' => '1985-06-10', 'country' => 'KZ',
                'created_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00',
            ]),
            'other_source' => Client::factory()->forOrganization($organization)->create([
                'gender' => 'FEMALE', 'birth_date' => '1985-06-10', 'country' => 'KZ',
                'created_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00',
            ]),
            'other_age' => Client::factory()->forOrganization($organization)->create([
                'gender' => 'FEMALE', 'birth_date' => '2000-06-10', 'country' => 'KZ',
                'created_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00',
            ]),
            'other_gender' => Client::factory()->forOrganization($organization)->create([
                'gender' => 'MALE', 'birth_date' => '1985-06-10', 'country' => 'KZ',
                'created_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00',
            ]),
            'other_service' => Client::factory()->forOrganization($organization)->create([
                'gender' => 'FEMALE', 'birth_date' => '1985-06-10', 'country' => 'KZ',
                'created_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00',
                'archived_at' => '2026-10-11 10:00:00',
            ]),
        ];
        foreach ($clients as $key => $client) {
            app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
                pains: [[
                    'name' => 'Шея',
                    'score' => match ($key) {
                        'target' => 5,
                        'without_tag' => 0,
                        'other_source' => 10,
                        default => null,
                    },
                    'tag_ids' => $key === 'target' ? [$tag->getKey()] : [],
                ]],
                painsProvided: true,
            ));
            $attribution = new ClientAttribution;
            $attribution->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'source_type' => 'source',
                'source' => $key === 'other_source' ? 'Telegram' : 'Instagram',
                'capture_channel' => 'synthetic',
                'captured_at' => '2026-10-10 10:00:00',
                'accepted_at' => '2026-10-10 10:00:00',
            ])->save();
        }

        $bookingAttributes = [
            'status' => BookingStatus::Completed->value,
            'starts_at' => '2026-10-15 10:00:00',
            'ends_at' => '2026-10-15 11:00:00',
            'blocking_ends_at' => '2026-10-15 11:00:00',
        ];
        $targetBooking = Booking::factory()->forOrganization($organization)->forClient($clients['target'])->forService($service)->forSpecialist($specialist)->create($bookingAttributes);
        BookingEvent::factory()->forBooking($targetBooking)->create([
            'event_type' => 'rescheduled',
            'occurred_at' => '2026-10-15 12:00:00',
        ]);
        Booking::factory()->forOrganization($organization)->forClient($clients['other_service'])->forService($otherService)->forSpecialist($secondSpecialist)->create($bookingAttributes);
        Booking::factory()->forOrganization($organization)->forClient($clients['other_gender'])->forService($service)->forSpecialist($specialist)->create([
            ...$bookingAttributes,
            'status' => BookingStatus::Cancelled->value,
        ]);
        Booking::factory()->forOrganization($organization)->forClient($clients['other_age'])->forService($service)->forSpecialist($secondSpecialist)->create([
            ...$bookingAttributes,
            'status' => BookingStatus::NoShow->value,
        ]);

        app(OrganizationContext::class)->set($organization);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );
        $filters = new ClientCohortFilters(
            problemTagIds: [$tag->getKey()],
            sourceKeys: ['Instagram'],
            genders: ['FEMALE'],
            ageMin: 30,
            ageMax: 44,
            serviceIds: [$service->getKey()],
            serviceCategories: ['Терапия'],
        );
        $ids = app(ClientCohortQuery::class)->for($admin, $filters, $period)->pluck('clients.id')->all();

        self::assertSame([$clients['target']->getKey()], $ids);
        self::assertSame(1, app(ClientCohortQuery::class)->previewCount($admin, $filters, $period));
        self::assertSame(1, app(DemographicsAnalytics::class)->handle($admin, $period, cohortFilters: $filters)->clientCount);
        self::assertSame(1, app(ServiceVisitAnalytics::class)->handle($admin, $period, cohortFilters: $filters)->visitCount);
        self::assertSame(1, app(AcquisitionAnalytics::class)->handle($admin, $period, cohortFilters: $filters)->newClients);
        self::assertSame([$clients['target']->getKey()], app(ClientCohortQuery::class)->for($admin, new ClientCohortFilters(bookingEventTypes: ['rescheduled']), $period)->pluck('clients.id')->all());
    }

    public function test_multiple_values_in_a_filter_are_or_and_tag_mode_can_require_all(): void
    {
        [$organization, $admin] = $this->fixture();
        $first = Client::factory()->forOrganization($organization)->create(['gender' => 'FEMALE']);
        $second = Client::factory()->forOrganization($organization)->create(['gender' => 'MALE']);
        $third = Client::factory()->forOrganization($organization)->create(['gender' => null]);

        app(OrganizationContext::class)->set($organization);
        $filters = new ClientCohortFilters(genders: ['FEMALE', 'MALE']);

        self::assertEqualsCanonicalizing(
            [$first->getKey(), $second->getKey()],
            app(ClientCohortQuery::class)->for($admin, $filters)->pluck('clients.id')->all(),
        );
        self::assertNotContains(
            $third->getKey(),
            app(ClientCohortQuery::class)->for($admin, $filters)->pluck('clients.id')->all(),
        );
    }

    public function test_demographics_uses_the_same_owner_configured_age_ranges_as_the_cohort_filter(): void
    {
        [$organization, $admin] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create([
            'birth_date' => '1985-06-10',
            'created_at' => '2026-10-10 10:00:00',
            'updated_at' => '2026-10-10 10:00:00',
        ]);
        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::AnalyticsAgeRanges,
            json_encode([[
                'key' => 'working_age',
                'label' => 'Рабочий возраст',
                'min' => 30,
                'max' => 44,
            ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );
        app(OrganizationContext::class)->set($organization);

        self::assertSame([$client->getKey()], app(ClientCohortQuery::class)->for($admin, new ClientCohortFilters(ageMin: 30, ageMax: 44), $period)->pluck('clients.id')->all());
        self::assertSame([['label' => 'Рабочий возраст', 'count' => 1, 'percent' => 100.0]], app(DemographicsAnalytics::class)->handle($admin, $period)->age);
    }

    public function test_booking_filters_use_the_same_selected_event_period_as_analytics_dimensions(): void
    {
        [$organization, $admin] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();

        $bookingAttributes = [
            'status' => BookingStatus::Completed->value,
            'starts_at' => '2026-01-15 10:00:00',
            'ends_at' => '2026-01-15 11:00:00',
            'blocking_ends_at' => '2026-01-15 11:00:00',
        ];
        Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forService($service)
            ->forSpecialist($specialist)
            ->create($bookingAttributes);

        app(OrganizationContext::class)->set($organization);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-02-01', 'end_date' => '2026-02-28'],
            'UTC',
            CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC'),
        );
        $filters = new ClientCohortFilters(serviceIds: [(int) $service->getKey()]);

        self::assertSame([], app(ClientCohortQuery::class)->for($admin, $filters, $period)->pluck('clients.id')->all());

        Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forService($service)
            ->forSpecialist($specialist)
            ->create([
                ...$bookingAttributes,
                'starts_at' => '2026-02-15 10:00:00',
                'ends_at' => '2026-02-15 11:00:00',
                'blocking_ends_at' => '2026-02-15 11:00:00',
            ]);

        self::assertSame([$client->getKey()], app(ClientCohortQuery::class)->for($admin, $filters, $period)->pluck('clients.id')->all());
    }

    public function test_pain_satisfaction_and_last_visit_filters_use_their_canonical_records(): void
    {
        [$organization, $admin] = $this->fixture();
        $matching = Client::factory()->forOrganization($organization)->create();
        $unrated = Client::factory()->forOrganization($organization)->create();
        $lateVisit = Client::factory()->forOrganization($organization)->create();
        app(UpdateMedicalProfile::class)->handle($admin, $matching, new UpdateMedicalProfileCommand(
            pains: [['name' => 'Шея', 'score' => 5], ['name' => 'Плечо']],
            painsProvided: true,
        ));
        $submission = new FeedbackSubmission;
        $submission->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $matching->getKey(),
            'score' => 0,
            'source' => 'portal',
            'idempotency_key' => 'cohort-satisfaction-zero',
            'request_hash' => hash('sha256', 'cohort-satisfaction-zero'),
            'submitted_at' => '2026-04-15 12:00:00',
        ])->save();
        $service = Service::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $booking = [
            'status' => BookingStatus::Completed->value,
            'starts_at' => '2026-04-15 10:00:00',
            'ends_at' => '2026-04-15 11:00:00',
            'blocking_ends_at' => '2026-04-15 11:00:00',
        ];
        Booking::factory()->forOrganization($organization)->forClient($matching)->forService($service)->forSpecialist($specialist)->create($booking);
        Booking::factory()->forOrganization($organization)->forClient($lateVisit)->forService($service)->forSpecialist($specialist)->create([
            ...$booking,
            'starts_at' => '2026-05-15 10:00:00',
            'ends_at' => '2026-05-15 11:00:00',
            'blocking_ends_at' => '2026-05-15 11:00:00',
        ]);

        app(OrganizationContext::class)->set($organization);
        $filters = new ClientCohortFilters(
            painScoreMin: 5,
            painScoreMax: 5,
            satisfactionMin: 0,
            satisfactionMax: 0,
            lastVisitFrom: '2026-04-01',
            lastVisitTo: '2026-04-30',
        );

        self::assertSame(
            [$matching->getKey()],
            app(ClientCohortQuery::class)->for($admin, $filters)->pluck('clients.id')->all(),
        );
        self::assertSame(
            [$matching->getKey()],
            app(ClientCohortQuery::class)->for($admin, new ClientCohortFilters(hasPainScore: true))->pluck('clients.id')->all(),
        );
        self::assertSame(
            [$unrated->getKey(), $lateVisit->getKey()],
            app(ClientCohortQuery::class)->for($admin, new ClientCohortFilters(hasPainScore: false))->pluck('clients.id')->sort()->values()->all(),
        );
    }

    public function test_query_requires_view_clients_permission(): void
    {
        [$organization] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $unauthorized = User::factory()->create();
        app(OrganizationContext::class)->set($organization);

        $this->expectException(AuthorizationException::class);
        app(ClientCohortQuery::class)->for($unauthorized, new ClientCohortFilters(clientIds: [$client->getKey()]));
    }

    public function test_manual_and_dynamic_saved_groups_are_versioned_and_used_by_the_same_cohort_query(): void
    {
        [$organization, $admin] = $this->fixture();
        $manualClient = Client::factory()->forOrganization($organization)->create();
        $dynamicClient = Client::factory()->forOrganization($organization)->create(['gender' => 'FEMALE']);
        Client::factory()->forOrganization($organization)->create(['gender' => 'MALE']);
        $save = app(SaveAnalyticsClientGroup::class);

        $manual = $save->handle($admin, [
            'name' => 'Ручная QA группа',
            'group_type' => 'manual',
            'is_active' => true,
            'sort_order' => 1,
        ], [$manualClient->getKey()]);
        $dynamic = $save->handle($admin, [
            'name' => 'Женщины QA',
            'group_type' => 'dynamic',
            'is_active' => true,
            'sort_order' => 2,
            'rule_definition' => ['genders' => ['FEMALE']],
        ]);

        self::assertSame(1, $manual->version);
        self::assertSame(1, $dynamic->version);
        self::assertDatabaseHas('analytics_client_group_versions', [
            'analytics_client_group_id' => $manual->getKey(),
            'version' => 1,
        ]);
        self::assertSame(
            [$manualClient->getKey(), $dynamicClient->getKey()],
            app(ClientCohortQuery::class)->for($admin, new ClientCohortFilters(savedGroupIds: [$manual->getKey(), $dynamic->getKey()]))->pluck('clients.id')->sort()->values()->all(),
        );
        self::assertSame('dynamic', AnalyticsClientGroup::query()->findOrFail($dynamic->getKey())->group_type);
    }

    public function test_saved_group_update_keeps_the_previous_definition_as_a_immutable_version(): void
    {
        [$organization, $admin] = $this->fixture();
        $firstClient = Client::factory()->forOrganization($organization)->create(['gender' => 'FEMALE']);
        $secondClient = Client::factory()->forOrganization($organization)->create(['gender' => 'MALE']);
        $save = app(SaveAnalyticsClientGroup::class);

        $group = $save->handle($admin, [
            'name' => 'Версионируемая группа',
            'group_type' => 'manual',
            'is_active' => true,
        ], [$firstClient->getKey()]);
        $save->handle($admin, [
            'id' => $group->getKey(),
            'name' => 'Версионируемая группа обновлена',
            'group_type' => 'manual',
            'is_active' => true,
        ], [$secondClient->getKey()]);

        self::assertSame([1, 2], AnalyticsClientGroupVersion::query()
            ->where('analytics_client_group_id', $group->getKey())
            ->orderBy('version')
            ->pluck('version')
            ->all());
        self::assertSame([$firstClient->getKey()], AnalyticsClientGroupVersion::query()
            ->where('analytics_client_group_id', $group->getKey())
            ->where('version', 1)
            ->firstOrFail()
            ->client_ids);
        self::assertSame([$secondClient->getKey()], AnalyticsClientGroupVersion::query()
            ->where('analytics_client_group_id', $group->getKey())
            ->where('version', 2)
            ->firstOrFail()
            ->client_ids);
    }

    public function test_inactive_saved_group_is_not_silently_ignored_by_a_restored_cohort(): void
    {
        [$organization, $admin] = $this->fixture();
        $group = app(SaveAnalyticsClientGroup::class)->handle($admin, [
            'name' => 'Неактивная QA группа',
            'group_type' => 'manual',
            'is_active' => false,
        ], []);
        app(OrganizationContext::class)->set($organization);

        $this->expectExceptionMessage('больше не активна');
        app(ClientCohortQuery::class)->for($admin, new ClientCohortFilters(savedGroupIds: [$group->getKey()]));
    }

    public function test_unknown_purchase_group_is_not_silently_treated_as_a_catalog_type(): void
    {
        [$organization, $admin] = $this->fixture();
        app(OrganizationContext::class)->set($organization);

        $this->expectExceptionMessage('групп покупок больше не активна');
        app(ClientCohortQuery::class)->for($admin, new ClientCohortFilters(purchaseTypes: ['removed_group']));
    }

    /** @return array{0: Organization, 1: User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }
}
