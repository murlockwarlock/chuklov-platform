<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\AnalyticsMetricFilters;
use App\Modules\Analytics\Application\Data\AnalyticsMetricResult;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricComparison;
use App\Modules\Analytics\Domain\Models\AnalyticsMetric;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class AnalyticsMetricConfiguration
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private SetOrganizationSetting $setter,
        private RecordAuditEvent $audit,
        private AnalyticsMetricDefinitionValidator $validator,
        private AnalyticsMetricEvaluator $evaluator,
    ) {}

    public function ensurePresets(): void
    {
        $organizationId = $this->context->id();
        $now = now();

        foreach ($this->presetDefinitions() as $preset) {
            DB::table('analytics_metric_configurations')->insertOrIgnore([
                [...$preset, 'organization_id' => $organizationId, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    /** @return Collection<int, AnalyticsMetric> */
    public function metrics(bool $activeOnly = false): Collection
    {
        $this->ensurePresets();

        return AnalyticsMetric::query()
            ->where('organization_id', $this->context->id())
            ->when($activeOnly, fn ($query) => $query->where('active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, array<string, mixed>> */
    public function formValues(): array
    {
        return $this->metrics()->map(fn (AnalyticsMetric $metric): array => $this->formValue($metric))->values()->all();
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return [
            'metrics' => $this->formValues(),
            ...$this->legacyValues(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data): void
    {
        if (array_key_exists('metrics', $data)) {
            $this->saveMetrics($actor, is_array($data['metrics']) ? array_values($data['metrics']) : []);
        }

        $this->saveLegacyReferences($actor, $data);
    }

    /** @param array<int, mixed> $metrics */
    public function saveMetrics(User $actor, array $metrics): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $this->ensurePresets();

        DB::transaction(function () use ($actor, $organization, $metrics): void {
            $existing = AnalyticsMetric::query()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (AnalyticsMetric $metric): int => (int) $metric->getKey());
            $known = $existing->filter(fn (AnalyticsMetric $metric): bool => (bool) $metric->active);
            $submittedIds = [];

            foreach ($metrics as $data) {
                if (! is_array($data)) {
                    throw ValidationException::withMessages(['metrics' => 'Проверьте настройки вычисляемых метрик.']);
                }

                $id = $this->positiveId($data['id'] ?? null);
                $metric = $id === null ? null : $existing->get($id);
                if ($id !== null && ! $metric instanceof AnalyticsMetric) {
                    throw ValidationException::withMessages(['metrics' => 'Выбранная метрика недоступна в этой организации.']);
                }
                if ($id !== null) {
                    $submittedIds[] = $id;
                }

                $normalized = $this->validator->validate($data, $metric, $known);
                $stableKey = $metric instanceof AnalyticsMetric ? $metric->stable_key : $this->newStableKey($existing);
                $wasChanged = $metric === null || $this->changed($metric, $normalized);
                $metric ??= new AnalyticsMetric;
                $metric->forceFill([
                    'organization_id' => $organization->getKey(),
                    'stable_key' => $stableKey,
                    ...$normalized,
                    'version' => $wasChanged ? ((int) ($metric->version ?? 0) + 1) : (int) ($metric->version ?? 1),
                ]);
                $metric->save();

                if ($wasChanged) {
                    $this->auditMetric($organization, $actor, $metric, $metric->wasRecentlyCreated ? 'analytics.metric.created' : 'analytics.metric.updated');
                }
            }

            foreach ($existing as $metric) {
                if (in_array((int) $metric->getKey(), $submittedIds, true) || $metric->active === false) {
                    continue;
                }

                $metric->forceFill([
                    'active' => false,
                    'version' => (int) $metric->version + 1,
                ])->save();
                $this->auditMetric($organization, $actor, $metric, 'analytics.metric.updated');
            }

            $activeIds = AnalyticsMetric::query()
                ->where('organization_id', $organization->getKey())
                ->where('active', true)
                ->pluck('id')
                ->all();
            if (AnalyticsMetric::query()
                ->where('organization_id', $organization->getKey())
                ->where('active', true)
                ->where('comparison', AnalyticsMetricComparison::Metric->value)
                ->whereNotIn('comparison_metric_id', $activeIds)
                ->exists()) {
                throw ValidationException::withMessages([
                    'comparison_metric_id' => 'Активная метрика должна сравниваться с активной метрикой этой организации.',
                ]);
            }
        });
    }

    /** @param array<string, mixed> $data */
    public function preview(
        User $actor,
        array $data,
        ?DashboardPeriod $period = null,
        ?AnalyticsMetricFilters $filters = null,
    ): AnalyticsMetricResult {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $known = $this->metrics()->filter(fn (AnalyticsMetric $metric): bool => (bool) $metric->active);
        $normalized = $this->validator->validate($data, null, $known);
        $metric = new AnalyticsMetric;
        $metric->forceFill([
            'stable_key' => 'preview',
            'version' => 1,
            ...$normalized,
        ]);
        $period ??= DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::LastThirtyDays],
            $organization->defaultTimezone(),
        );

        return $this->evaluator->evaluate(
            $actor,
            $period,
            [...$known->values()->all(), $metric],
            null,
            $filters ?? AnalyticsMetricFilters::empty(),
        )['preview'];
    }

    /** @param array<string, mixed> $data */
    private function saveLegacyReferences(User $actor, array $data): void
    {
        foreach ([
            OrganizationSettingKey::AnalyticsTherapyEffectivenessFormulaReference->value => $data['therapy_effectiveness_formula_reference'] ?? null,
            OrganizationSettingKey::AnalyticsLtvPercentFormulaReference->value => $data['ltv_percent_formula_reference'] ?? null,
        ] as $settingKey => $reference) {
            $key = OrganizationSettingKey::from((string) $settingKey);
            if ($reference === null) {
                continue;
            }
            if (! is_string($reference)) {
                throw ValidationException::withMessages([$key->value => 'Старое описание настройки заполнено некорректно.']);
            }

            $normalized = trim($reference);
            if (mb_strlen($normalized) > 2000) {
                throw ValidationException::withMessages([$key->value => 'Старое описание настройки не должно превышать 2000 символов.']);
            }

            $this->setter->handle($actor, $key, $normalized);
        }
    }

    private function auditMetric(Organization $organization, User $actor, AnalyticsMetric $metric, string $action): void
    {
        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: $action,
            targetType: AnalyticsMetric::class,
            targetId: (string) $metric->getKey(),
            metadata: [
                'stable_key' => $metric->stable_key,
                'active' => (bool) $metric->active,
                'version' => (int) $metric->version,
                'source_a' => $metric->source_a_key,
                'source_b' => $metric->source_b_key,
                'aggregation' => (string) $metric->getRawOriginal('aggregation'),
                'calculation' => (string) $metric->getRawOriginal('calculation'),
                'comparison' => (string) $metric->getRawOriginal('comparison'),
                'output_unit' => (string) $metric->getRawOriginal('output_unit'),
                'decimal_precision' => (int) $metric->decimal_precision,
                'null_policy' => (string) $metric->getRawOriginal('null_policy'),
                'zero_policy' => (string) $metric->getRawOriginal('zero_policy'),
            ],
        );
    }

    /** @param array<string, mixed> $data */
    private function changed(AnalyticsMetric $metric, array $data): bool
    {
        foreach (array_keys($data) as $key) {
            $current = $key === 'target_value' ? $metric->getRawOriginal($key) : $metric->{$key};
            if ($current instanceof \BackedEnum) {
                $current = $current->value;
            }
            if ((string) $current !== (string) $data[$key]) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function formValue(AnalyticsMetric $metric): array
    {
        return [
            'id' => $metric->getKey(),
            'stable_key' => $metric->stable_key,
            'name' => $metric->name,
            'description' => $metric->description,
            'active' => (bool) $metric->active,
            'output_unit' => (string) $metric->getRawOriginal('output_unit'),
            'decimal_precision' => (int) $metric->decimal_precision,
            'source_a_key' => $metric->source_a_key,
            'source_b_key' => $metric->source_b_key,
            'aggregation' => (string) $metric->getRawOriginal('aggregation'),
            'calculation' => (string) $metric->getRawOriginal('calculation'),
            'comparison' => (string) $metric->getRawOriginal('comparison'),
            'comparison_metric_id' => $metric->comparison_metric_id,
            'target_value' => $metric->getRawOriginal('target_value'),
            'null_policy' => (string) $metric->getRawOriginal('null_policy'),
            'zero_policy' => (string) $metric->getRawOriginal('zero_policy'),
            'sort_order' => (int) $metric->sort_order,
            'version' => (int) $metric->version,
        ];
    }

    /** @return array{therapy_effectiveness: array<string, mixed>, ltv_percent: array<string, mixed>} */
    private function legacyValues(): array
    {
        $settings = $this->context->organization()->settings()
            ->whereIn('setting_key', [
                OrganizationSettingKey::AnalyticsTherapyEffectivenessFormulaReference->value,
                OrganizationSettingKey::AnalyticsLtvPercentFormulaReference->value,
            ])
            ->get()
            ->keyBy('setting_key');

        return [
            'therapy_effectiveness' => $this->legacyValue(
                'Снижение боли, %',
                $settings->get(OrganizationSettingKey::AnalyticsTherapyEffectivenessFormulaReference->value)?->string_value,
            ),
            'ltv_percent' => $this->legacyValue(
                'LTV новых клиентов',
                $settings->get(OrganizationSettingKey::AnalyticsLtvPercentFormulaReference->value)?->string_value,
            ),
        ];
    }

    /** @return array{label: string, formula_reference: ?string, configured: bool} */
    private function legacyValue(string $label, ?string $setting): array
    {
        $reference = trim((string) $setting);

        return [
            'label' => $label,
            'formula_reference' => $reference === '' ? null : $reference,
            'configured' => $reference !== '',
        ];
    }

    /** @param Collection<int, AnalyticsMetric> $existing */
    private function newStableKey(Collection $existing): string
    {
        do {
            $key = 'custom_'.Str::lower(Str::random(24));
        } while ($existing->contains(fn (AnalyticsMetric $metric): bool => $metric->stable_key === $key));

        return $key;
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }

    /** @return list<array<string, mixed>> */
    private function presetDefinitions(): array
    {
        return [
            [
                'stable_key' => 'pain_reduction_percent',
                'name' => 'Снижение боли, %',
                'description' => 'Процентное снижение средней боли до и после сеанса.',
                'active' => true,
                'output_unit' => 'percent',
                'decimal_precision' => 2,
                'source_a_key' => AnalyticsMetricSourceCatalog::PainAverageBefore,
                'source_b_key' => AnalyticsMetricSourceCatalog::PainAverageAfter,
                'aggregation' => 'average',
                'calculation' => 'reduction_percent',
                'comparison' => 'none',
                'comparison_metric_id' => null,
                'target_value' => null,
                'null_policy' => 'do_not_calculate',
                'zero_policy' => 'do_not_calculate',
                'sort_order' => 10,
                'version' => 1,
            ],
            [
                'stable_key' => 'ltv_change_percent',
                'name' => 'Изменение LTV к предыдущему периоду, %',
                'description' => 'Изменение денежного LTV новых клиентов относительно предыдущего сопоставимого периода.',
                'active' => true,
                'output_unit' => 'percent',
                'decimal_precision' => 2,
                'source_a_key' => AnalyticsMetricSourceCatalog::FinanceNewClientLtv,
                'source_b_key' => null,
                'aggregation' => 'average',
                'calculation' => 'change_percent',
                'comparison' => 'previous_period',
                'comparison_metric_id' => null,
                'target_value' => null,
                'null_policy' => 'do_not_calculate',
                'zero_policy' => 'do_not_calculate',
                'sort_order' => 20,
                'version' => 1,
            ],
        ];
    }
}
