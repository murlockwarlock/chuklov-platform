<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\FinanceAnalyticsData;
use App\Modules\Analytics\Application\FinanceAnalytics;
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

    protected ?string $heading = 'Финансы';

    protected ?string $description = 'Только подтверждённые данные финансового журнала';

    protected static ?int $sort = 5;

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
        $suffix = $currency === '' ? '' : ' ('.$currency.')';
        $unavailable = $data === null || ! $data->available;

        return [
            Stat::make('Выручка'.$suffix, $unavailable ? '—' : $this->formatMinor($data->revenueMinor, $currency))
                ->description($unavailable ? 'Расчёт недоступен' : 'Нетто по финансовому журналу'),
            Stat::make('Средний платёж'.$suffix, $unavailable ? '—' : $this->formatMinor($data->averageReceiptMinor, $currency))
                ->description($this->receiptDescription($data, $unavailable)),
            Stat::make('Реализованный LTV'.$suffix, $unavailable ? '—' : $this->formatMinor($data->realizedLtvMinor, $currency))
                ->description($this->ltvDescription($data, $unavailable)),
            Stat::make('Долг на конец периода'.$suffix, $unavailable ? '—' : $this->formatMinor($data->debtMinor, $currency))
                ->description($unavailable ? 'Расчёт недоступен' : 'Обязательства минус журнал до конца периода'),
        ];
    }

    private function formatMinor(?string $minor, string $currency): string
    {
        if ($minor === null || $currency === '') {
            return '—';
        }

        try {
            $scale = app(CurrencyCatalog::class)->scale($currency);

            return BigDecimal::ofUnscaledValue($minor, $scale)
                ->toScale($scale)
                ->toString().' '.$currency;
        } catch (\Throwable) {
            return '—';
        }
    }

    private function receiptDescription(?FinanceAnalyticsData $data, bool $unavailable): string
    {
        if ($unavailable || $data === null) {
            return 'Расчёт недоступен';
        }

        return $data->receiptCount === 0
            ? 'Нет положительных платежей за период'
            : 'Положительных платежей: '.$data->receiptCount;
    }

    private function ltvDescription(?FinanceAnalyticsData $data, bool $unavailable): string
    {
        if ($unavailable || $data === null) {
            return 'Расчёт недоступен';
        }

        return $data->cohortClientCount === 0
            ? 'Нет новых клиентов за период'
            : 'Средняя историческая стоимость новых клиентов';
    }
}
