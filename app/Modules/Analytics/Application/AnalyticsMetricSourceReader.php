<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\AnalyticsMetricFilters;
use App\Modules\Analytics\Application\Data\AnalyticsMetricSourceValue;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricSourceKind;
use App\Modules\Finance\Application\FinancialReconciliationContract;
use App\Modules\Identity\Domain\Models\Client;

final readonly class AnalyticsMetricSourceReader
{
    public function __construct(
        private PainAnalytics $pain,
        private FinanceAnalytics $finance,
        private AcquisitionAnalytics $acquisition,
        private SchedulingAnalytics $scheduling,
        private FeedbackAnalytics $feedback,
        private DemographicsAnalytics $demographics,
        private ServiceVisitAnalytics $services,
        private PurchaseAnalytics $purchases,
        private BookingStatusAnalytics $bookingStatuses,
        private ClientSegmentAnalytics $segments,
        private FinancialReconciliationContract $money,
    ) {}

    /**
     * @param  list<string>  $sourceKeys
     * @return array<string, AnalyticsMetricSourceValue>
     */
    public function read(
        User $actor,
        DashboardPeriod $period,
        ?Client $client,
        AnalyticsMetricFilters $filters,
        array $sourceKeys,
    ): array {
        $values = [];
        $sourceKeys = array_values(array_unique(array_filter($sourceKeys, 'is_string')));

        if ($this->hasPrefix($sourceKeys, 'pain.')) {
            $data = $this->pain->handle($actor, $period, $client, $filters->problemTagId);
            $this->add($values, AnalyticsMetricSourceCatalog::PainAverageBefore, $data->averageBefore);
            $this->add($values, AnalyticsMetricSourceCatalog::PainAverageAfter, $data->averageAfter);
            $this->add($values, AnalyticsMetricSourceCatalog::PainAverageReduction, $data->averageReduction);
            $this->add($values, AnalyticsMetricSourceCatalog::PainAverageReductionPercent, $data->averageReductionPercent);
            $this->add($values, AnalyticsMetricSourceCatalog::PainClientCount, (float) $data->clientCount);
            $this->add($values, AnalyticsMetricSourceCatalog::PainSessionCount, (float) $data->sessionCount);
        }

        if ($this->hasPrefix($sourceKeys, 'finance.')) {
            $data = $this->finance->handle($actor, $period, $client);
            $this->addMoney($values, AnalyticsMetricSourceCatalog::FinanceRevenue, $data->revenueMinor, $data->baseCurrency);
            $this->addMoney($values, AnalyticsMetricSourceCatalog::FinanceAverageReceipt, $data->averageReceiptMinor, $data->baseCurrency);
            $this->addMoney($values, AnalyticsMetricSourceCatalog::FinanceNewClientLtv, $data->realizedLtvMinor, $data->baseCurrency);
            $this->addMoney($values, AnalyticsMetricSourceCatalog::FinanceDebt, $data->debtMinor, $data->baseCurrency);
            $this->addMoney($values, AnalyticsMetricSourceCatalog::FinanceExpenses, $data->expensesMinor, $data->baseCurrency);
            $this->addMoney($values, AnalyticsMetricSourceCatalog::FinanceCashMovement, $data->cashMovementMinor, $data->baseCurrency);
            $this->add($values, AnalyticsMetricSourceCatalog::FinanceReceiptCount, (float) $data->receiptCount);
            $this->add($values, AnalyticsMetricSourceCatalog::FinanceNewClientCount, (float) $data->cohortClientCount);
        }

        if ($this->hasPrefix($sourceKeys, 'acquisition.')) {
            $data = $this->acquisition->handle($actor, $period, $client, $filters->sourceKey);
            $this->add($values, AnalyticsMetricSourceCatalog::AcquisitionNewClients, (float) $data->newClients);
        }

        if ($this->hasPrefix($sourceKeys, 'scheduling.')) {
            $data = $this->scheduling->handle($actor, $period, $client);
            $this->add($values, AnalyticsMetricSourceCatalog::SchedulingBookings, (float) $data->bookings);
            $this->add($values, AnalyticsMetricSourceCatalog::SchedulingVisits, (float) $data->visits);
            $this->add($values, AnalyticsMetricSourceCatalog::SchedulingCancellations, (float) $data->cancellations);
            $this->add($values, AnalyticsMetricSourceCatalog::SchedulingReschedules, (float) $data->reschedules);
            $this->add($values, AnalyticsMetricSourceCatalog::SchedulingNoShows, (float) $data->noShows);
        }

        if ($this->hasPrefix($sourceKeys, 'feedback.')) {
            $data = $this->feedback->handle($actor, $period, $client, $filters->feedbackThemeId);
            $this->add($values, AnalyticsMetricSourceCatalog::FeedbackAverageScore, $data->averageScore);
            $this->add($values, AnalyticsMetricSourceCatalog::FeedbackSubmissionCount, (float) $data->submissionCount);
        }

        if ($this->hasPrefix($sourceKeys, 'demographics.')) {
            $data = $this->demographics->handle($actor, $period, $client);
            $this->add($values, AnalyticsMetricSourceCatalog::DemographicsClientCount, (float) $data->clientCount);
        }

        if ($this->hasPrefix($sourceKeys, 'services.')) {
            $data = $this->services->handle($actor, $period, $client);
            $this->add($values, AnalyticsMetricSourceCatalog::ServiceVisitCount, (float) $data->visitCount);
        }

        if ($this->hasPrefix($sourceKeys, 'purchases.')) {
            $data = $this->purchases->handle($actor, $period, $client, $filters->purchaseType);
            $this->add($values, AnalyticsMetricSourceCatalog::PurchaseCount, (float) array_sum(array_column($data->types, 'count')));
        }

        if ($this->hasPrefix($sourceKeys, 'booking_statuses.')) {
            $data = $this->bookingStatuses->handle($actor, $period, $client, $filters->bookingStatus);
            $counts = [];
            foreach ($data->statuses as $status) {
                $counts[$status['key']] = (float) $status['count'];
            }
            $this->add($values, AnalyticsMetricSourceCatalog::BookingStatusCompleted, $counts['completed'] ?? null);
            $this->add($values, AnalyticsMetricSourceCatalog::BookingStatusCancelled, $counts['cancelled'] ?? null);
            $this->add($values, AnalyticsMetricSourceCatalog::BookingStatusRescheduled, $counts['rescheduled'] ?? null);
        }

        if ($this->hasPrefix($sourceKeys, 'segments.')) {
            $data = $this->segments->handle($actor, $client);
            $this->add($values, AnalyticsMetricSourceCatalog::SegmentClientCount, (float) $data['client_count']);
        }

        return array_intersect_key($values, array_flip($sourceKeys));
    }

    /** @param array<string, AnalyticsMetricSourceValue> $values */
    private function add(array &$values, string $key, ?float $value): void
    {
        $kind = AnalyticsMetricSourceCatalog::kind($key);
        if ($kind === null) {
            return;
        }

        $values[$key] = new AnalyticsMetricSourceValue($key, $kind, $value);
    }

    /** @param array<string, AnalyticsMetricSourceValue> $values */
    private function addMoney(array &$values, string $key, ?string $minor, string $currency): void
    {
        $value = null;
        if ($minor !== null && $currency !== '') {
            try {
                $code = $this->money->currency($currency);
                $value = (float) $this->money->money($minor, $code, 'A persisted financial amount is invalid.')
                    ->toDecimalString();
            } catch (\Throwable) {
                $value = null;
            }
        }

        $values[$key] = new AnalyticsMetricSourceValue(
            key: $key,
            kind: AnalyticsMetricSourceKind::Money,
            value: $value,
            currency: $currency === '' ? null : $currency,
        );
    }

    /** @param list<string> $sourceKeys */
    private function hasPrefix(array $sourceKeys, string $prefix): bool
    {
        foreach ($sourceKeys as $sourceKey) {
            if (str_starts_with($sourceKey, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
