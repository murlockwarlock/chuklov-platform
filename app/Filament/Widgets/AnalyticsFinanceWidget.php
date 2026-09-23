<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FinancialObligations\FinancialObligationResource;
use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\FinanceAnalyticsData;
use App\Modules\Analytics\Application\FinanceAnalytics;
use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Brick\Math\BigDecimal;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class AnalyticsFinanceWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 5;

    protected function getHeading(): ?string
    {
        return __('Финансы');
    }

    protected function getDescription(): ?string
    {
        return __('Оплата и задолженность за выбранный период');
    }

    public static function canView(): bool
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            return false;
        }

        try {
            $organization = app(OrganizationContext::class)->organization();

            return app(OrganizationAuthorizer::class)->allows(
                $actor,
                $organization,
                OrganizationPermission::ViewFinance,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    public function getData(): ?FinanceAnalyticsData
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            return null;
        }

        $organization = app(OrganizationContext::class)->organization();
        $period = DashboardPeriod::fromFilters($this->pageFilters, $organization->defaultTimezone());

        return app(FinanceAnalytics::class)->handle($actor, $period);
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $data = $this->getData();
        $currency = $data === null ? '' : $data->baseCurrency;
        $unavailable = $data === null || ! $data->available;

        return [
            Stat::make(__('Выручка'), $unavailable ? __('Расчёт недоступен') : $this->formatMinor($data->revenueMinor, $currency))
                ->description($unavailable ? __('Расчёт недоступен') : __('По подтверждённым платежам')),
            Stat::make(__('Средний чек'), $unavailable ? __('Расчёт недоступен') : $this->formatMinor($data->averageReceiptMinor, $currency))
                ->description($this->receiptDescription($data, $unavailable)),
            Stat::make(__('LTV новых клиентов'), $unavailable ? __('Расчёт недоступен') : $this->formatMinor($data->realizedLtvMinor, $currency))
                ->description($this->ltvDescription($data, $unavailable)),
            Stat::make(__('Дебиторская задолженность'), $unavailable ? __('Расчёт недоступен') : $this->formatMinor($data->debtMinor, $currency))
                ->description($unavailable ? __('Расчёт недоступен') : __('Показать должников'))
                ->url($unavailable ? null : FinancialObligationResource::getUrl('index', [
                    'filters' => ['status' => ['value' => FinancialStatus::Outstanding->value]],
                ])),
        ];
    }

    private function formatMinor(?string $minor, string $currency): string
    {
        if ($minor === null || $currency === '') {
            return __('Нет данных');
        }

        try {
            $scale = app(CurrencyCatalog::class)->scale($currency);

            return BigDecimal::ofUnscaledValue($minor, $scale)
                ->toScale($scale)
                ->toString().' '.$currency;
        } catch (\Throwable) {
            return __('Нет данных');
        }
    }

    private function receiptDescription(?FinanceAnalyticsData $data, bool $unavailable): string
    {
        if ($unavailable || $data === null) {
            return __('Расчёт недоступен');
        }

        return $data->receiptCount === 0
            ? __('Нет подтверждённых платежей за период')
            : __('Подтверждённых платежей: :count', ['count' => $data->receiptCount]);
    }

    private function ltvDescription(?FinanceAnalyticsData $data, bool $unavailable): string
    {
        if ($unavailable || $data === null) {
            return __('Расчёт недоступен');
        }

        return $data->cohortClientCount === 0
            ? __('Нет новых клиентов за период')
            : __('Фактически полученный доход · клиентов в когорте: :count', ['count' => $data->cohortClientCount]);
    }
}
