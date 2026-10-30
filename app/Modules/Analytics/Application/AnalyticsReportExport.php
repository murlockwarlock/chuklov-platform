<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Finance\Application\FinancialReconciliationContract;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class AnalyticsReportExport
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private FinancialReconciliationContract $money,
    ) {}

    /**
     * @param  array<string, mixed>  $results
     * @param  array<string, mixed>  $metadata
     */
    public function download(
        User $actor,
        array $results,
        string $filename = 'analytics-report.xlsx',
        array $metadata = [],
    ): StreamedResponse {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);

        $spreadsheet = new Spreadsheet;
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Сводка');
        $this->writeSummary($summary, $metadata, $results);

        if (isset($results['acquisition']) && is_array($results['acquisition'])) {
            $this->writeSources($this->newSheet($spreadsheet, 'Источники'), $results['acquisition']);
        }
        if (isset($results['demographics']) && is_array($results['demographics'])) {
            $this->writeDemographics($this->newSheet($spreadsheet, 'Демография'), $results['demographics']);
        }
        if (isset($results['pain']) && is_array($results['pain'])) {
            $this->writePain($this->newSheet($spreadsheet, 'Боли'), $results['pain']);
        }
        if (isset($results['services']) && is_array($results['services'])) {
            $this->writeServices($this->newSheet($spreadsheet, 'Услуги'), $results['services']);
            $this->writeSpecialists($this->newSheet($spreadsheet, 'Специалисты'), $results['services']);
        }
        if (isset($results['purchases']) && is_array($results['purchases'])) {
            $this->writePurchases($this->newSheet($spreadsheet, 'Покупки'), $results['purchases']);
        }
        if (isset($results['booking_statuses']) && is_array($results['booking_statuses'])) {
            $this->writeBookings($this->newSheet($spreadsheet, 'Записи'), $results['booking_statuses']);
        }
        if (isset($results['segments']) && is_array($results['segments'])) {
            $this->writeSegments($this->newSheet($spreadsheet, 'Категории клиентов'), $results['segments']);
        }
        if (isset($results['feedback']) && is_array($results['feedback'])) {
            $this->writeFeedback($this->newSheet($spreadsheet, 'Обратная связь'), $results['feedback']);
        }
        if (isset($results['goals']) && is_array($results['goals'])) {
            $this->writeGoals($this->newSheet($spreadsheet, 'Цели и задачи'), $results['goals']);
        }
        if (isset($results['finance']) && is_array($results['finance'])) {
            $this->writeFinance($this->newSheet($spreadsheet, 'Финансы'), $results['finance']);
        }

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $this->autosize($sheet);
            $sheet->freezePane('A2');
        }

        return response()->streamDownload(
            static function () use ($spreadsheet): void {
                (new Xlsx($spreadsheet))->save('php://output');
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** @param array<string, mixed> $metadata
     * @param  array<string, mixed>  $results
     */
    private function writeSummary(Worksheet $sheet, array $metadata, array $results): void
    {
        $rows = [];
        foreach ([
            'period' => 'Период',
            'client' => 'Клиент',
            'filters' => 'Фильтры',
            'timezone' => 'Часовой пояс',
            'currency' => 'Валюта',
        ] as $key => $label) {
            $value = $metadata[$key] ?? null;
            if ($value !== null && $value !== '') {
                $rows[] = [$label, is_array($value) ? implode(', ', $value) : $value];
            }
        }

        $rows[] = ['', '', null];

        if (isset($results['acquisition']['new_clients'])) {
            $rows[] = ['Новые клиенты', $results['acquisition']['new_clients'], null];
        }
        if (isset($results['scheduling']) && is_array($results['scheduling'])) {
            foreach ([
                'bookings' => 'Записи',
                'visits' => 'Визиты',
                'cancellations' => 'Отмены',
                'reschedules' => 'Переносы',
                'no_shows' => 'Неявки',
            ] as $key => $label) {
                if (array_key_exists($key, $results['scheduling'])) {
                    $rows[] = [$label, $results['scheduling'][$key], null];
                }
            }
        }
        if (isset($results['pain']) && is_array($results['pain'])) {
            foreach ([
                'client_count' => 'Клиенты с проблемами',
                'session_count' => 'Сеансы с оценкой боли',
            ] as $key => $label) {
                if (array_key_exists($key, $results['pain'])) {
                    $rows[] = [$label, $results['pain'][$key], null];
                }
            }
        }
        if (isset($results['demographics']['client_count'])) {
            $rows[] = ['Клиенты в демографии', $results['demographics']['client_count'], null];
        }
        if (isset($results['services']['visit_count'])) {
            $rows[] = ['Визиты по услугам', $results['services']['visit_count'], null];
            $rows[] = ['Специалисты в отчёте', count((array) ($results['services']['specialists'] ?? [])), null];
        }
        if (isset($results['feedback']) && is_array($results['feedback'])) {
            foreach ([
                'submission_count' => 'Получено оценок',
                'average_score' => 'Средняя оценка',
            ] as $key => $label) {
                if (array_key_exists($key, $results['feedback'])) {
                    $rows[] = [$label, $results['feedback'][$key], null];
                }
            }
        }
        if (isset($results['goals']['goals']) && is_array($results['goals']['goals'])) {
            $rows[] = ['Категории целей', count($results['goals']['goals']), null];
        }
        if (isset($results['segments']['client_count'])) {
            $rows[] = ['Клиенты в сегментах', $results['segments']['client_count'], null];
        }
        if (isset($results['finance']) && is_array($results['finance']) && ($results['finance']['available'] ?? false)) {
            $currency = $results['finance']['currency'] ?? null;
            foreach ([
                'revenue_display' => 'Выручка',
                'average_receipt_display' => 'Средний чек',
                'realized_ltv_display' => 'LTV новых клиентов',
                'debt_display' => 'Долг',
                'expenses_display' => 'Расходы',
                'cash_movement_display' => 'Денежный поток',
            ] as $key => $label) {
                if (array_key_exists($key, $results['finance']) && $results['finance'][$key] !== null) {
                    $rows[] = [$label, $results['finance'][$key], $currency];
                }
            }
        }
        if (isset($results['custom_metrics']['metrics']) && is_array($results['custom_metrics']['metrics'])) {
            foreach ($results['custom_metrics']['metrics'] as $metric) {
                if (! is_array($metric)) {
                    continue;
                }

                $unit = (string) ($metric['unit'] ?? '');
                if (($metric['currency'] ?? null) !== null && $metric['currency'] !== '') {
                    $unit .= ' '.(string) $metric['currency'];
                }
                $rows[] = [
                    (string) ($metric['name'] ?? 'Показатель'),
                    ($metric['available'] ?? false) ? ($metric['value'] ?? null) : ($metric['display'] ?? null),
                    $unit,
                ];
            }
        }

        $this->writeRows($sheet, $rows, ['Показатель', 'Значение', 'Единица']);
    }

    /** @param array<string, mixed> $data */
    private function writeSources(Worksheet $sheet, array $data): void
    {
        $total = max(0, (int) ($data['new_clients'] ?? 0));
        $rows = [];
        foreach ((array) ($data['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }
            $count = (int) ($source['count'] ?? 0);
            $rows[] = [(string) ($source['label'] ?? '—'), $count, $total === 0 ? null : round($count / $total * 100, 2)];
        }

        $this->writeRows($sheet, $rows, ['Источник', 'Новые клиенты', 'Доля, %']);
    }

    /** @param array<string, mixed> $data */
    private function writeDemographics(Worksheet $sheet, array $data): void
    {
        $rows = [];
        foreach (['age' => 'Возраст', 'sex' => 'Пол', 'children' => 'Дети и взрослые', 'country' => 'Страна'] as $key => $label) {
            foreach ((array) ($data[$key] ?? []) as $item) {
                if (is_array($item)) {
                    $rows[] = [$label, (string) ($item['label'] ?? '—'), (int) ($item['count'] ?? 0), $item['percent'] ?? null];
                }
            }
        }

        $this->writeRows($sheet, $rows, ['Параметр', 'Значение', 'Количество', 'Доля, %']);
    }

    /** @param array<string, mixed> $data */
    private function writePain(Worksheet $sheet, array $data): void
    {
        $rows = [];
        foreach ((array) ($data['problems'] ?? []) as $problem) {
            if (is_array($problem)) {
                $rows[] = [
                    (string) ($problem['name'] ?? '—'),
                    $problem['group'] ?? null,
                    (int) ($problem['client_count'] ?? 0),
                    $problem['average_before'] ?? null,
                    $problem['average_after'] ?? null,
                    $problem['average_reduction'] ?? null,
                    $problem['average_reduction_percent'] ?? null,
                ];
            }
        }

        $this->writeRows($sheet, $rows, ['Проблема', 'Группа', 'Клиентов', 'Среднее до', 'Среднее после', 'Снижение', 'Снижение, %']);
    }

    /** @param array<string, mixed> $data */
    private function writeServices(Worksheet $sheet, array $data): void
    {
        $rows = [];
        foreach ((array) ($data['services'] ?? []) as $service) {
            if (is_array($service)) {
                $rows[] = [(string) ($service['name'] ?? '—'), (int) ($service['client_count'] ?? 0), (int) ($service['visit_count'] ?? 0)];
            }
        }

        $this->writeRows($sheet, $rows, ['Услуга', 'Клиентов', 'Визитов']);
    }

    /** @param array<string, mixed> $data */
    private function writeSpecialists(Worksheet $sheet, array $data): void
    {
        $rows = [];
        foreach ((array) ($data['specialists'] ?? []) as $specialist) {
            if (is_array($specialist)) {
                $rows[] = [(string) ($specialist['name'] ?? '—'), (int) ($specialist['client_count'] ?? 0), (int) ($specialist['visit_count'] ?? 0)];
            }
        }

        $this->writeRows($sheet, $rows, ['Специалист', 'Клиентов', 'Визитов']);
    }

    /** @param array<string, mixed> $data */
    private function writePurchases(Worksheet $sheet, array $data): void
    {
        $rows = [];
        foreach ((array) ($data['types'] ?? []) as $type) {
            if (is_array($type)) {
                $rows[] = [(string) ($type['label'] ?? '—'), (int) ($type['client_count'] ?? 0), (int) ($type['count'] ?? 0)];
            }
        }

        $this->writeRows($sheet, $rows, ['Тип покупки', 'Клиентов', 'Количество']);
    }

    /** @param array<string, mixed> $data */
    private function writeBookings(Worksheet $sheet, array $data): void
    {
        $rows = [];
        foreach ((array) ($data['statuses'] ?? []) as $status) {
            if (is_array($status)) {
                $rows[] = [(string) ($status['label'] ?? '—'), (int) ($status['count'] ?? 0)];
            }
        }

        $this->writeRows($sheet, $rows, ['Статус', 'Количество']);
    }

    /** @param array<string, mixed> $data */
    private function writeSegments(Worksheet $sheet, array $data): void
    {
        $rows = [];
        foreach ((array) ($data['segments'] ?? []) as $segment) {
            if (is_array($segment)) {
                $rows[] = [(string) ($segment['label'] ?? '—'), (int) ($segment['count'] ?? 0), $segment['percent'] ?? null];
            }
        }

        $this->writeRows($sheet, $rows, ['Категория', 'Клиентов', 'Доля, %']);
    }

    /** @param array<string, mixed> $data */
    private function writeFeedback(Worksheet $sheet, array $data): void
    {
        $rows = [
            ['Количество оценок', $data['submission_count'] ?? 0, null, null, null],
            ['Средняя оценка', $data['average_score'] ?? null, null, null, null],
        ];
        foreach ((array) ($data['themes'] ?? []) as $theme) {
            if (is_array($theme)) {
                $rows[] = ['Тема', null, (string) ($theme['name'] ?? '—'), (int) ($theme['client_count'] ?? 0), $theme['average_score'] ?? null];
            }
        }

        $this->writeRows($sheet, $rows, ['Показатель', 'Значение', 'Тема', 'Клиентов', 'Средняя оценка']);
    }

    /** @param array<string, mixed> $data */
    private function writeGoals(Worksheet $sheet, array $data): void
    {
        $rows = [];
        foreach ((array) ($data['goals'] ?? []) as $goal) {
            if (is_array($goal)) {
                $rows[] = [(string) ($goal['name'] ?? '—'), $goal['group'] ?? null, (int) ($goal['client_count'] ?? 0), (int) ($goal['task_count'] ?? 0)];
            }
        }

        $this->writeRows($sheet, $rows, ['Цель или задача', 'Группа', 'Клиентов', 'Задач']);
    }

    /** @param array<string, mixed> $data */
    private function writeFinance(Worksheet $sheet, array $data): void
    {
        $currency = $data['currency'] ?? null;
        $rows = [];
        foreach ([
            'revenue_display' => 'Выручка',
            'average_receipt_display' => 'Средний чек',
            'realized_ltv_display' => 'LTV новых клиентов',
            'debt_display' => 'Долг',
            'expenses_display' => 'Расходы',
            'cash_movement_display' => 'Денежный поток',
        ] as $key => $label) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $rows[] = [$label, $data[$key], $currency];
            }
        }
        $this->writeRows($sheet, $rows, ['Показатель', 'Значение', 'Валюта']);

        $accounts = [];
        foreach ((array) ($data['accounts'] ?? []) as $account) {
            if (is_array($account)) {
                $accounts[] = [
                    (string) ($account['label'] ?? '—'),
                    self::humanAccountType((string) ($account['account_type'] ?? '')),
                    (string) ($account['currency'] ?? '—'),
                    $account['movement_display'] ?? $this->formatMoney($account['movement_minor'] ?? null, $account['movement_currency'] ?? $account['currency'] ?? null),
                    $currency,
                ];
            }
        }
        if ($accounts !== []) {
            $start = count($rows) + 3;
            $sheet->fromArray([['Счёт', 'Тип', 'Валюта счёта', 'Движение за период', 'Базовая валюта']], null, 'A'.$start, true);
            foreach ($accounts as $index => $account) {
                $sheet->fromArray([$account], null, 'A'.($start + $index + 1), true);
            }
        }
    }

    private function newSheet(Spreadsheet $spreadsheet, string $title): Worksheet
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        return $sheet;
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     * @param  list<string>  $headers
     */
    private function writeRows(Worksheet $sheet, array $rows, array $headers): void
    {
        $sheet->fromArray([$headers], null, 'A1', true);
        foreach ($rows as $index => $row) {
            $sheet->fromArray([$row], null, 'A'.($index + 2), true);
        }
    }

    private function autosize(Worksheet $sheet): void
    {
        $highestColumn = $sheet->getHighestColumn();
        foreach (range(1, Coordinate::columnIndexFromString($highestColumn)) as $index) {
            $sheet->getColumnDimensionByColumn($index)->setAutoSize(true);
        }
        $sheet->getStyle('A1:'.$highestColumn.'1')->getFont()->setBold(true);
    }

    private function formatMoney(mixed $minor, mixed $currency): ?string
    {
        if ($minor === null || $currency === null || $currency === '') {
            return null;
        }

        try {
            $code = $this->money->currency($currency);

            return $this->money->money($minor, $code, 'A persisted financial amount is invalid.')
                ->toDecimalString().' '.$code->value;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function humanAccountType(string $type): string
    {
        return match ($type) {
            'cash' => 'Касса',
            'bank' => 'Банковский счёт',
            'other' => 'Другое',
            default => 'Счёт',
        };
    }
}
