<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FinancialObligations\FinancialObligationResource;
use App\Models\User;
use App\Modules\Analytics\Application\AcquisitionAnalytics;
use App\Modules\Analytics\Application\Data\AcquisitionAnalyticsData;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\FinanceAnalyticsData;
use App\Modules\Analytics\Application\Data\SchedulingAnalyticsData;
use App\Modules\Analytics\Application\FinanceAnalytics;
use App\Modules\Analytics\Application\SchedulingAnalytics;
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

final class AnalyticsKpiWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Ключевые показатели';

    protected ?string $description = 'Главные показатели бизнеса за выбранный период';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            return false;
        }

        try {
            $organization = app(OrganizationContext::class)->organization();
            $authorizer = app(OrganizationAuthorizer::class);

            foreach ([
                OrganizationPermission::ViewClients,
                OrganizationPermission::ViewScheduling,
                OrganizationPermission::ViewFinance,
            ] as $permission) {
                if ($authorizer->allows($actor, $organization, $permission)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /** @return array<string, AcquisitionAnalyticsData|FinanceAnalyticsData|SchedulingAnalyticsData|null> */
    public function getData(): array
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            return [];
        }

        $organization = app(OrganizationContext::class)->organization();
        $period = DashboardPeriod::fromFilters($this->pageFilters, $organization->defaultTimezone());
        $authorizer = app(OrganizationAuthorizer::class);

        return [
            'acquisition' => $authorizer->allows($actor, $organization, OrganizationPermission::ViewClients)
                ? app(AcquisitionAnalytics::class)->handle($actor, $period)
                : null,
            'scheduling' => $authorizer->allows($actor, $organization, OrganizationPermission::ViewScheduling)
                ? app(SchedulingAnalytics::class)->handle($actor, $period)
                : null,
            'finance' => $authorizer->allows($actor, $organization, OrganizationPermission::ViewFinance)
                ? app(FinanceAnalytics::class)->handle($actor, $period)
                : null,
        ];
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $data = $this->getData();
        $stats = [];
        $acquisition = $data['acquisition'] ?? null;
        $scheduling = $data['scheduling'] ?? null;
        $finance = $data['finance'] ?? null;

        if ($acquisition instanceof AcquisitionAnalyticsData) {
            $stats[] = Stat::make('Новые клиенты', (string) $acquisition->newClients)
                ->description('Созданы за выбранный период');
        }

        if ($scheduling instanceof SchedulingAnalyticsData) {
            $stats[] = Stat::make('Завершённые визиты', (string) $scheduling->visits)
                ->description('По подтверждённой истории записей');
        }

        if ($finance instanceof FinanceAnalyticsData) {
            $currency = $finance->baseCurrency;
            $unavailable = ! $finance->available;

            $stats[] = Stat::make('Выручка', $this->financeValue($finance->revenueMinor, $currency, $unavailable))
                ->description($unavailable ? 'Расчёт недоступен' : 'Подтверждённые операции финансового журнала');
            $stats[] = Stat::make('Средний чек', $this->financeValue($finance->averageReceiptMinor, $currency, $unavailable))
                ->description($this->receiptDescription($finance, $unavailable));
            $stats[] = Stat::make('LTV новых клиентов', $this->financeValue($finance->realizedLtvMinor, $currency, $unavailable))
                ->description($this->ltvDescription($finance, $unavailable));

            $debt = Stat::make('Дебиторская задолженность', $this->financeValue($finance->debtMinor, $currency, $unavailable))
                ->description($unavailable ? 'Расчёт недоступен' : 'Показать должников');

            if (! $unavailable) {
                $debt->url(FinancialObligationResource::getUrl('index', [
                    'filters' => ['status' => ['value' => FinancialStatus::Outstanding->value]],
                ]));
            }

            $stats[] = $debt;
        }

        return $stats;
    }

    private function financeValue(?string $minor, string $currency, bool $unavailable): string
    {
        if ($unavailable) {
            return 'Расчёт недоступен';
        }

        if ($minor === null || $currency === '') {
            return 'Нет данных';
        }

        try {
            $scale = app(CurrencyCatalog::class)->scale($currency);

            return BigDecimal::ofUnscaledValue($minor, $scale)
                ->toScale($scale)
                ->toString().' '.$currency;
        } catch (\Throwable) {
            return 'Нет данных';
        }
    }

    private function receiptDescription(FinanceAnalyticsData $data, bool $unavailable): string
    {
        if ($unavailable) {
            return 'Расчёт недоступен';
        }

        return $data->receiptCount === 0
            ? 'Нет подтверждённых платежей за период'
            : 'Подтверждённых платежей: '.$data->receiptCount;
    }

    private function ltvDescription(FinanceAnalyticsData $data, bool $unavailable): string
    {
        if ($unavailable) {
            return 'Расчёт недоступен';
        }

        return $data->cohortClientCount === 0
            ? 'Нет новых клиентов за период'
            : 'Фактически полученный доход · клиентов в когорте: '.$data->cohortClientCount;
    }
}
