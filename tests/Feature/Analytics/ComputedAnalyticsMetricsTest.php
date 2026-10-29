<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\AnalyticsMetricConfiguration;
use App\Modules\Analytics\Application\AnalyticsMetricEvaluator;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Domain\Models\AnalyticsMetric;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ComputedAnalyticsMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pain_preset_is_calculated_from_authoritative_pain_projection(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->painSession($admin, $client, $specialist, 8, 4);
        $period = $this->period($organization);
        $metric = $this->preset('pain_reduction_percent');

        $result = app(AnalyticsMetricEvaluator::class)->evaluate($admin, $period, [$metric])[$metric->stable_key];

        self::assertSame(50.0, $result->value);
        self::assertSame('50,00 %', $result->formatted());
    }

    public function test_pain_preset_returns_no_value_for_zero_or_missing_after_score(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->painSession($admin, $client, $specialist, 0, 0);
        $period = $this->period($organization);
        $metric = $this->preset('pain_reduction_percent');

        $zeroResult = app(AnalyticsMetricEvaluator::class)->evaluate($admin, $period, [$metric])[$metric->stable_key];

        self::assertNull($zeroResult->value);
        self::assertSame('Недостаточно данных', $zeroResult->formatted());
    }

    public function test_pain_preset_returns_no_value_when_after_score_is_missing(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->painSession($admin, $client, $specialist, 8, null);
        $period = $this->period($organization);
        $metric = $this->preset('pain_reduction_percent');

        $result = app(AnalyticsMetricEvaluator::class)->evaluate($admin, $period, [$metric])[$metric->stable_key];

        self::assertNull($result->value);
    }

    public function test_ltv_preset_compares_current_cohort_with_the_previous_comparable_period(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->configureFinance($organization);
        $this->cohortPayment($organization, '2026-09-10 09:00:00', 10000, 'previous-ltv');
        $this->cohortPayment($organization, '2026-10-10 09:00:00', 12000, 'current-ltv');
        $period = $this->period($organization);
        $metric = $this->preset('ltv_change_percent');

        $result = app(AnalyticsMetricEvaluator::class)->evaluate($admin, $period, [$metric])[$metric->stable_key];

        self::assertSame(20.0, $result->value);
        self::assertSame('+20,00 %', $result->formatted());
    }

    public function test_custom_metric_can_compare_an_authoritative_source_with_a_target(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->painSession($admin, $client, $specialist, 8, 4);
        $configuration = app(AnalyticsMetricConfiguration::class);
        $metrics = $configuration->formValues();
        $metrics[] = [
            ...$this->customMetric('Снижение к цели', 'reduction_percent', 'percent'),
            'source_a_key' => 'pain.average_before',
            'source_b_key' => null,
            'calculation' => 'reduction_percent',
            'comparison' => 'target',
            'target_value' => '4',
        ];
        $configuration->saveMetrics($admin, $metrics);
        $metric = AnalyticsMetric::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', 'Снижение к цели')
            ->sole();

        $result = app(AnalyticsMetricEvaluator::class)->evaluate($admin, $this->period($organization), [$metric])[$metric->stable_key];

        self::assertSame(50.0, $result->value);
    }

    public function test_custom_ratio_has_a_number_output_unit(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->painSession($admin, $client, $specialist, 8, 4);
        $configuration = app(AnalyticsMetricConfiguration::class);
        $metrics = $configuration->formValues();
        $metrics[] = [
            ...$this->customMetric('Соотношение боли', 'ratio', 'number'),
        ];
        $configuration->saveMetrics($admin, $metrics);
        $metric = AnalyticsMetric::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', 'Соотношение боли')
            ->sole();

        $result = app(AnalyticsMetricEvaluator::class)->evaluate($admin, $this->period($organization), [$metric])[$metric->stable_key];

        self::assertSame(2.0, $result->value);
        self::assertSame('2,00', $result->formatted());
    }

    public function test_metric_comparison_uses_the_source_unit_for_percent_operations(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->painSession($admin, $client, $specialist, 8, 4);
        $configuration = app(AnalyticsMetricConfiguration::class);
        $metrics = $configuration->formValues();
        $metrics[] = [
            ...$this->customMetric('Средняя боль', 'value', 'score'),
            'source_a_key' => 'pain.average_before',
            'source_b_key' => null,
            'calculation' => 'value',
        ];
        $configuration->saveMetrics($admin, $metrics);
        $base = AnalyticsMetric::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', 'Средняя боль')
            ->sole();

        $metrics = $configuration->formValues();
        $metrics[] = [
            ...$this->customMetric('Изменение к средней боли', 'change_percent', 'percent'),
            'source_a_key' => 'pain.average_before',
            'source_b_key' => null,
            'calculation' => 'change_percent',
            'comparison' => 'metric',
            'comparison_metric_id' => $base->getKey(),
        ];
        $configuration->saveMetrics($admin, $metrics);
        $metric = AnalyticsMetric::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', 'Изменение к средней боли')
            ->sole();

        $result = app(AnalyticsMetricEvaluator::class)->evaluate($admin, $this->period($organization), [$base, $metric])[$metric->stable_key];

        self::assertSame(0.0, $result->value);
    }

    public function test_percentage_source_keeps_a_percent_output_unit(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->painSession($admin, $client, $specialist, 8, 4);
        $configuration = app(AnalyticsMetricConfiguration::class);
        $metrics = $configuration->formValues();
        $metrics[] = [
            ...$this->customMetric('Готовое снижение боли', 'value', 'percent'),
            'source_a_key' => 'pain.average_reduction_percent',
            'source_b_key' => null,
            'calculation' => 'value',
            'aggregation' => 'average',
        ];
        $configuration->saveMetrics($admin, $metrics);
        $metric = AnalyticsMetric::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', 'Готовое снижение боли')
            ->sole();

        $result = app(AnalyticsMetricEvaluator::class)->evaluate($admin, $this->period($organization), [$metric])[$metric->stable_key];

        self::assertSame(50.0, $result->value);
        self::assertSame('50,00 %', $result->formatted());
    }

    public function test_owner_can_edit_persist_and_deactivate_a_custom_metric(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->painSession($admin, $client, $specialist, 8, 4);
        $configuration = app(AnalyticsMetricConfiguration::class);
        $metrics = $configuration->formValues();
        $metrics[] = $this->customMetric('Разница боли', 'difference', 'score');

        $configuration->saveMetrics($admin, $metrics);
        $custom = AnalyticsMetric::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', 'Разница боли')
            ->sole();

        self::assertSame(1, $custom->version);
        self::assertDatabaseHas('audit_events', [
            'organization_id' => $organization->getKey(),
            'action' => 'analytics.metric.created',
            'target_id' => (string) $custom->getKey(),
        ]);
        $period = $this->period($organization);
        $beforeEdit = app(AnalyticsMetricEvaluator::class)->evaluate($admin, $period, [$custom])[$custom->stable_key];
        self::assertSame(4.0, $beforeEdit->value);

        $edited = $configuration->formValues();
        $edited = array_map(function (array $metric) use ($custom): array {
            if ((int) ($metric['id'] ?? 0) === (int) $custom->getKey()) {
                $metric['name'] = 'Разница боли после настройки';
                $metric['output_unit'] = 'percent';
                $metric['calculation'] = 'reduction_percent';
            }

            return $metric;
        }, $edited);
        $configuration->saveMetrics($admin, $edited);

        $custom->refresh();
        self::assertSame('Разница боли после настройки', $custom->name);
        self::assertSame(2, $custom->version);
        $afterEdit = app(AnalyticsMetricEvaluator::class)->evaluate($admin, $period, [$custom])[$custom->stable_key];
        self::assertSame(50.0, $afterEdit->value);

        $deactivated = array_map(function (array $metric) use ($custom): array {
            if ((int) ($metric['id'] ?? 0) === (int) $custom->getKey()) {
                $metric['active'] = false;
            }

            return $metric;
        }, $configuration->formValues());
        $configuration->saveMetrics($admin, $deactivated);

        self::assertFalse($custom->refresh()->active);
        self::assertFalse($configuration->metrics(true)->contains('id', $custom->getKey()));
    }

    public function test_preset_edits_are_not_overwritten_by_reprovisioning(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $configuration = app(AnalyticsMetricConfiguration::class);
        $metrics = array_map(function (array $metric): array {
            if ($metric['stable_key'] === 'pain_reduction_percent') {
                $metric['name'] = 'Моё снижение боли';
                $metric['active'] = false;
            }

            return $metric;
        }, $configuration->formValues());

        $configuration->saveMetrics($admin, $metrics);
        $configuration->ensurePresets();

        $preset = AnalyticsMetric::query()
            ->where('organization_id', $organization->getKey())
            ->where('stable_key', 'pain_reduction_percent')
            ->sole();
        self::assertSame('Моё снижение боли', $preset->name);
        self::assertFalse($preset->active);
    }

    public function test_incompatible_sources_are_rejected_by_backend_validation(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $configuration = app(AnalyticsMetricConfiguration::class);
        $metrics = $configuration->formValues();
        $metrics[] = [
            ...$this->customMetric('Несовместимая метрика', 'difference', 'score'),
            'source_a_key' => 'pain.average_before',
            'source_b_key' => 'acquisition.new_clients',
        ];

        $this->expectException(ValidationException::class);
        $configuration->saveMetrics($admin, $metrics);
    }

    public function test_custom_metric_configuration_is_tenant_scoped(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $configuration = app(AnalyticsMetricConfiguration::class);
        $metrics = $configuration->formValues();
        $metrics[] = $this->customMetric('Первая организация', 'difference', 'score');
        $configuration->saveMetrics($admin, $metrics);
        $foreignMetric = AnalyticsMetric::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', 'Первая организация')
            ->sole();

        [$otherOrganization, $otherAdmin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($otherOrganization);

        self::assertFalse($configuration->metrics()->contains('id', $foreignMetric->getKey()));
        self::assertSame([], app(AnalyticsMetricEvaluator::class)->evaluate($otherAdmin, $this->period($otherOrganization), [$foreignMetric]));
        $this->expectException(ValidationException::class);
        $configuration->saveMetrics($otherAdmin, [[
            'id' => $foreignMetric->getKey(),
            ...$this->customMetric('Чужая метрика', 'difference', 'score'),
        ]]);
    }

    private function preset(string $stableKey): AnalyticsMetric
    {
        return app(AnalyticsMetricConfiguration::class)->metrics(true)->firstOrFail(
            fn (AnalyticsMetric $metric): bool => $metric->stable_key === $stableKey,
        );
    }

    /** @return array<string, mixed> */
    private function customMetric(string $name, string $calculation, string $unit): array
    {
        return [
            'name' => $name,
            'description' => null,
            'active' => true,
            'output_unit' => $unit,
            'decimal_precision' => 2,
            'source_a_key' => 'pain.average_before',
            'source_b_key' => 'pain.average_after',
            'aggregation' => 'average',
            'calculation' => $calculation,
            'comparison' => 'none',
            'comparison_metric_id' => null,
            'target_value' => null,
            'null_policy' => 'do_not_calculate',
            'zero_policy' => 'do_not_calculate',
            'sort_order' => 100,
        ];
    }

    private function painSession(
        User $admin,
        Client $client,
        Specialist $specialist,
        ?int $before,
        ?int $after,
    ): void {
        $tag = MedicalProblemTag::query()->create([
            'organization_id' => $client->organization_id,
            'name' => 'Синтетическая боль',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC'),
            pains: [[
                'name' => 'Синтетическая боль',
                'tag_ids' => [$tag->getKey()],
                'before_score' => $before,
                'after_score' => $after,
            ]],
        ));
    }

    private function cohortPayment(Organization $organization, string $createdAt, int $amountMinor, string $key): void
    {
        $created = CarbonImmutable::parse($createdAt, 'UTC');
        $client = Client::factory()->forOrganization($organization)->create([
            'created_at' => $created,
            'updated_at' => $created,
        ]);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => $created->addDay(),
                'ends_at' => $created->addDay()->addHour(),
                'blocking_ends_at' => $created->addDay()->addHour(),
            ]);

        $money = Money::ofMinor($amountMinor, 'USD');
        $snapshot = [
            'source_amount_minor' => (string) $amountMinor,
            'source_currency' => 'USD',
            'target_amount_minor' => (string) $amountMinor,
            'target_currency' => 'USD',
            'rate' => '1',
            'rate_id' => null,
            'rate_version' => null,
            'effective_at' => null,
            'rounding_mode' => FinancialRoundingMode::HalfUp->value,
            'source_scale' => $money->scale(),
            'target_scale' => $money->scale(),
        ];
        $obligation = new FinancialObligation;
        $obligation->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'booking_id' => $booking->getKey(),
            'service_id' => $service->getKey(),
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
            'base_amount_minor' => $amountMinor,
            'base_currency' => 'USD',
            'display_amount_minor' => $amountMinor,
            'display_currency' => 'USD',
            'payment_amount_minor' => $amountMinor,
            'payment_currency' => 'USD',
            'settlement_amount_minor' => $amountMinor,
            'settlement_currency' => 'USD',
            'price_snapshot' => ['amount_minor' => (string) $amountMinor],
            'conversion_snapshots' => ['base' => $snapshot, 'display' => $snapshot],
            'creation_key' => $key.'-obligation',
            'created_at' => $created,
            'updated_at' => $created,
        ])->save();

        $entry = new FinancialLedgerEntry;
        $entry->forceFill([
            'organization_id' => $organization->getKey(),
            'obligation_id' => $obligation->getKey(),
            'entry_type' => 'manual_payment',
            'source' => 'crm',
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
            'payment_amount_minor' => $amountMinor,
            'payment_currency' => 'USD',
            'base_amount_minor' => $amountMinor,
            'base_currency' => 'USD',
            'display_amount_minor' => $amountMinor,
            'display_currency' => 'USD',
            'settlement_amount_minor' => $amountMinor,
            'settlement_currency' => 'USD',
            'conversion_snapshot' => null,
            'payment_method' => 'cash',
            'occurred_at' => $created->addDay(),
            'note' => null,
            'actor_user_id' => null,
            'provider_reference' => null,
            'idempotency_key' => $key.'-payment',
            'corrects_ledger_entry_id' => null,
        ])->save();
    }

    private function configureFinance(Organization $organization): void
    {
        $timestamp = now();
        DB::table('organization_currency_configurations')->insert([
            'organization_id' => $organization->getKey(),
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'force_single_currency' => true,
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
    }

    private function period(Organization $organization): DashboardPeriod
    {
        return DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            $organization->defaultTimezone(),
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );
    }

    /** @return array{0: Organization, 1: User, 2: Client, 3: Specialist} */
    private function fixture(): array
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();

        return [$organization, $admin, $client, $specialist];
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }
}
