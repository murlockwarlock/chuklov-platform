<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\Enums\AnalyticsMetricAggregation;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricCalculation;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricSourceKind;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricUnit;

final class AnalyticsMetricSourceCatalog
{
    public const string PainAverageBefore = 'pain.average_before';

    public const string PainAverageAfter = 'pain.average_after';

    public const string PainAverageReduction = 'pain.average_reduction';

    public const string PainAverageReductionPercent = 'pain.average_reduction_percent';

    public const string PainClientCount = 'pain.client_count';

    public const string PainSessionCount = 'pain.session_count';

    public const string FinanceRevenue = 'finance.revenue';

    public const string FinanceAverageReceipt = 'finance.average_receipt';

    public const string FinanceNewClientLtv = 'finance.new_client_ltv';

    public const string FinanceDebt = 'finance.debt';

    public const string FinanceExpenses = 'finance.expenses';

    public const string FinanceCashMovement = 'finance.cash_movement';

    public const string FinanceReceiptCount = 'finance.receipt_count';

    public const string FinanceNewClientCount = 'finance.new_client_count';

    public const string AcquisitionNewClients = 'acquisition.new_clients';

    public const string SchedulingBookings = 'scheduling.bookings';

    public const string SchedulingVisits = 'scheduling.visits';

    public const string SchedulingCancellations = 'scheduling.cancellations';

    public const string SchedulingReschedules = 'scheduling.reschedules';

    public const string SchedulingNoShows = 'scheduling.no_shows';

    public const string FeedbackAverageScore = 'feedback.average_score';

    public const string FeedbackSubmissionCount = 'feedback.submission_count';

    public const string DemographicsClientCount = 'demographics.client_count';

    public const string ServiceVisitCount = 'services.visit_count';

    public const string PurchaseCount = 'purchases.count';

    public const string BookingStatusCompleted = 'booking_statuses.completed';

    public const string BookingStatusCancelled = 'booking_statuses.cancelled';

    public const string BookingStatusRescheduled = 'booking_statuses.rescheduled';

    public const string SegmentClientCount = 'segments.client_count';

    /** @return array<string, array{label: string, kind: AnalyticsMetricSourceKind, filter: ?string, allowed_aggregations: array<string, string>}> */
    public static function definitions(): array
    {
        return [
            self::PainAverageBefore => self::makeDefinition('Средняя боль до сеанса', AnalyticsMetricSourceKind::Score, 'problem_tag_id', AnalyticsMetricAggregation::Average),
            self::PainAverageAfter => self::makeDefinition('Средняя боль после сеанса', AnalyticsMetricSourceKind::Score, 'problem_tag_id', AnalyticsMetricAggregation::Average),
            self::PainAverageReduction => self::makeDefinition('Абсолютное снижение боли', AnalyticsMetricSourceKind::Score, 'problem_tag_id', AnalyticsMetricAggregation::Average),
            self::PainAverageReductionPercent => self::makeDefinition('Среднее снижение боли, %', AnalyticsMetricSourceKind::Number, 'problem_tag_id', AnalyticsMetricAggregation::Average),
            self::PainClientCount => self::makeDefinition('Количество клиентов с проблемой', AnalyticsMetricSourceKind::Count, 'problem_tag_id', AnalyticsMetricAggregation::Count),
            self::PainSessionCount => self::makeDefinition('Количество сеансов с оценкой боли', AnalyticsMetricSourceKind::Count, 'problem_tag_id', AnalyticsMetricAggregation::Count),
            self::FinanceRevenue => self::makeDefinition('Выручка', AnalyticsMetricSourceKind::Money, null, AnalyticsMetricAggregation::Sum),
            self::FinanceAverageReceipt => self::makeDefinition('Средний чек', AnalyticsMetricSourceKind::Money, null, AnalyticsMetricAggregation::Average),
            self::FinanceNewClientLtv => self::makeDefinition('LTV новых клиентов', AnalyticsMetricSourceKind::Money, null, AnalyticsMetricAggregation::Average),
            self::FinanceDebt => self::makeDefinition('Долг', AnalyticsMetricSourceKind::Money, null, AnalyticsMetricAggregation::Sum),
            self::FinanceExpenses => self::makeDefinition('Расходы', AnalyticsMetricSourceKind::Money, null, AnalyticsMetricAggregation::Sum),
            self::FinanceCashMovement => self::makeDefinition('Денежный поток', AnalyticsMetricSourceKind::Money, null, AnalyticsMetricAggregation::Sum),
            self::FinanceReceiptCount => self::makeDefinition('Количество платежей', AnalyticsMetricSourceKind::Count, null, AnalyticsMetricAggregation::Count),
            self::FinanceNewClientCount => self::makeDefinition('Количество новых клиентов в когорте', AnalyticsMetricSourceKind::Count, null, AnalyticsMetricAggregation::Count),
            self::AcquisitionNewClients => self::makeDefinition('Новые клиенты', AnalyticsMetricSourceKind::Count, 'source_key', AnalyticsMetricAggregation::Count),
            self::SchedulingBookings => self::makeDefinition('Записи', AnalyticsMetricSourceKind::Count, null, AnalyticsMetricAggregation::Count),
            self::SchedulingVisits => self::makeDefinition('Визиты', AnalyticsMetricSourceKind::Count, null, AnalyticsMetricAggregation::Count),
            self::SchedulingCancellations => self::makeDefinition('Отмены', AnalyticsMetricSourceKind::Count, null, AnalyticsMetricAggregation::Count),
            self::SchedulingReschedules => self::makeDefinition('Переносы', AnalyticsMetricSourceKind::Count, null, AnalyticsMetricAggregation::Count),
            self::SchedulingNoShows => self::makeDefinition('Неявки', AnalyticsMetricSourceKind::Count, null, AnalyticsMetricAggregation::Count),
            self::FeedbackAverageScore => self::makeDefinition('Средняя оценка обратной связи', AnalyticsMetricSourceKind::Score, 'feedback_theme_id', AnalyticsMetricAggregation::Average),
            self::FeedbackSubmissionCount => self::makeDefinition('Количество оценок', AnalyticsMetricSourceKind::Count, 'feedback_theme_id', AnalyticsMetricAggregation::Count),
            self::DemographicsClientCount => self::makeDefinition('Клиенты в демографии', AnalyticsMetricSourceKind::Count, null, AnalyticsMetricAggregation::Count),
            self::ServiceVisitCount => self::makeDefinition('Завершённые визиты по услугам', AnalyticsMetricSourceKind::Count, null, AnalyticsMetricAggregation::Count),
            self::PurchaseCount => self::makeDefinition('Количество покупок', AnalyticsMetricSourceKind::Count, 'purchase_type', AnalyticsMetricAggregation::Count),
            self::BookingStatusCompleted => self::makeDefinition('Завершённые записи', AnalyticsMetricSourceKind::Count, 'booking_status', AnalyticsMetricAggregation::Count),
            self::BookingStatusCancelled => self::makeDefinition('Отменённые записи', AnalyticsMetricSourceKind::Count, 'booking_status', AnalyticsMetricAggregation::Count),
            self::BookingStatusRescheduled => self::makeDefinition('Перенесённые записи', AnalyticsMetricSourceKind::Count, 'booking_status', AnalyticsMetricAggregation::Count),
            self::SegmentClientCount => self::makeDefinition('Клиенты в категориях', AnalyticsMetricSourceKind::Count, null, AnalyticsMetricAggregation::Count),
        ];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::definitions() as $key => $definition) {
            $options[$key] = $definition['label'];
        }

        return $options;
    }

    /** @return array{label: string, kind: AnalyticsMetricSourceKind, filter: ?string, allowed_aggregations: array<string, string>} */
    public static function definition(string $key): array
    {
        $definition = self::definitions()[$key] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException('Unknown analytics metric source.');
        }

        return $definition;
    }

    public static function kind(string $key): ?AnalyticsMetricSourceKind
    {
        $definition = self::definitions()[$key] ?? null;

        return $definition === null ? null : $definition['kind'];
    }

    public static function filter(string $key): ?string
    {
        $definition = self::definitions()[$key] ?? null;

        return $definition === null ? null : $definition['filter'];
    }

    /** @return array<string, string> */
    public static function aggregationOptions(string $key): array
    {
        return self::definitions()[$key]['allowed_aggregations'] ?? [];
    }

    public static function defaultAggregation(string $key): string
    {
        $options = self::aggregationOptions($key);
        $default = array_key_last($options);

        return is_string($default) ? $default : AnalyticsMetricAggregation::SourceValue->value;
    }

    public static function compatible(string $sourceA, ?string $sourceB, AnalyticsMetricCalculation $calculation): bool
    {
        if (! $calculation->requiresSecondValue()) {
            return $sourceB === null;
        }

        if ($sourceB === null) {
            return false;
        }

        $kindA = self::kind($sourceA);
        $kindB = self::kind($sourceB);

        return $kindA !== null && $kindB !== null && $kindA === $kindB;
    }

    public static function outputUnit(string $sourceA, AnalyticsMetricCalculation $calculation): ?AnalyticsMetricUnit
    {
        if ($calculation->producesPercent()) {
            return AnalyticsMetricUnit::Percent;
        }
        if ($calculation === AnalyticsMetricCalculation::Ratio) {
            return AnalyticsMetricUnit::Number;
        }

        if ($sourceA === self::PainAverageReductionPercent) {
            return AnalyticsMetricUnit::Percent;
        }

        return match (self::kind($sourceA)) {
            AnalyticsMetricSourceKind::Money => AnalyticsMetricUnit::Money,
            AnalyticsMetricSourceKind::Score => AnalyticsMetricUnit::Score,
            AnalyticsMetricSourceKind::Count,
            AnalyticsMetricSourceKind::Number => AnalyticsMetricUnit::Number,
            null => null,
        };
    }

    public static function aggregationAllowed(string $sourceKey, AnalyticsMetricAggregation $aggregation): bool
    {
        return array_key_exists($aggregation->value, self::aggregationOptions($sourceKey));
    }

    /** @return array{label: string, kind: AnalyticsMetricSourceKind, filter: ?string, allowed_aggregations: array<string, string>} */
    private static function makeDefinition(
        string $label,
        AnalyticsMetricSourceKind $kind,
        ?string $filter = null,
        ?AnalyticsMetricAggregation $canonicalAggregation = null,
    ): array {
        $canonicalAggregation ??= match ($kind) {
            AnalyticsMetricSourceKind::Money => AnalyticsMetricAggregation::Sum,
            AnalyticsMetricSourceKind::Count => AnalyticsMetricAggregation::Count,
            AnalyticsMetricSourceKind::Score,
            AnalyticsMetricSourceKind::Number => AnalyticsMetricAggregation::Average,
        };

        return [
            'label' => $label,
            'kind' => $kind,
            'filter' => $filter,
            'allowed_aggregations' => self::aggregationOptionsForCanonical($canonicalAggregation),
        ];
    }

    /** @return array<string, string> */
    private static function aggregationOptionsForCanonical(AnalyticsMetricAggregation $canonicalAggregation): array
    {
        $options = [
            AnalyticsMetricAggregation::SourceValue->value => AnalyticsMetricAggregation::SourceValue->label(),
        ];
        if ($canonicalAggregation !== AnalyticsMetricAggregation::SourceValue) {
            $options[$canonicalAggregation->value] = $canonicalAggregation->label();
        }

        return $options;
    }
}
