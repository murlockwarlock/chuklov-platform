<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class AnalyticsReportExport
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
    ) {}

    /** @param array<string, mixed> $results */
    public function download(User $actor, array $results, string $filename = 'analytics-report.xlsx'): StreamedResponse
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Отчёт');
        $sheet->fromArray([
            ['Раздел', 'Показатель', 'Значение', 'Группа', 'До', 'После', 'Снижение'],
        ], null, 'A1');
        $row = 2;

        if (isset($results['pain']) && is_array($results['pain'])) {
            foreach ((array) ($results['pain']['problems'] ?? []) as $problem) {
                $sheet->fromArray([[
                    'Проблемы и динамика боли',
                    (string) ($problem['name'] ?? '—'),
                    (string) ($problem['client_count'] ?? 0),
                    (string) ($problem['group'] ?? '—'),
                    $problem['average_before'] ?? null,
                    $problem['average_after'] ?? null,
                    $problem['average_reduction'] ?? null,
                ]], null, 'A'.$row++);
            }
        }

        if (isset($results['acquisition']) && is_array($results['acquisition'])) {
            $sheet->fromArray([['Источники клиентов', 'Новые клиенты', (int) ($results['acquisition']['new_clients'] ?? 0), null, null, null, null]], null, 'A'.$row++);
            foreach ((array) ($results['acquisition']['sources'] ?? []) as $source) {
                $sheet->fromArray([['Источники клиентов', (string) ($source['label'] ?? '—'), (int) ($source['count'] ?? 0), null, null, null, null]], null, 'A'.$row++);
            }
        }

        if (isset($results['feedback']) && is_array($results['feedback'])) {
            $sheet->fromArray([['Удовлетворённость и обратная связь', 'Оценок', (int) ($results['feedback']['submission_count'] ?? 0), null, null, null, null]], null, 'A'.$row++);
            $sheet->fromArray([['Удовлетворённость и обратная связь', 'Средняя оценка', $results['feedback']['average_score'] ?? null, null, null, null, null]], null, 'A'.$row++);
            foreach ((array) ($results['feedback']['themes'] ?? []) as $theme) {
                $sheet->fromArray([['Темы обратной связи', (string) ($theme['name'] ?? '—'), (int) ($theme['client_count'] ?? 0), $theme['group'] ?? null, null, $theme['average_score'] ?? null, null]], null, 'A'.$row++);
            }
        }

        if (isset($results['goals']) && is_array($results['goals'])) {
            foreach ((array) ($results['goals']['goals'] ?? []) as $goal) {
                $sheet->fromArray([['Цели и задачи', (string) ($goal['name'] ?? '—'), (int) ($goal['client_count'] ?? 0), $goal['group'] ?? null, (int) ($goal['task_count'] ?? 0), null, null]], null, 'A'.$row++);
            }
        }

        if (isset($results['demographics']) && is_array($results['demographics'])) {
            foreach (['age' => 'Возраст', 'sex' => 'Пол', 'children' => 'Дети и взрослые', 'country' => 'Страна'] as $key => $label) {
                foreach ((array) ($results['demographics'][$key] ?? []) as $item) {
                    $sheet->fromArray([['Демография клиентов', $label.': '.(string) ($item['label'] ?? '—'), (int) ($item['count'] ?? 0), null, null, $item['percent'] ?? null, null]], null, 'A'.$row++);
                }
            }
        }

        if (isset($results['services']) && is_array($results['services'])) {
            foreach ((array) ($results['services']['services'] ?? []) as $service) {
                $sheet->fromArray([['Услуги и визиты', (string) ($service['name'] ?? '—'), (int) ($service['visit_count'] ?? 0), null, (int) ($service['client_count'] ?? 0), null, null]], null, 'A'.$row++);
            }
        }

        if (isset($results['purchases']) && is_array($results['purchases'])) {
            foreach ((array) ($results['purchases']['types'] ?? []) as $type) {
                $sheet->fromArray([['Типы покупок', (string) ($type['label'] ?? '—'), (int) ($type['count'] ?? 0), null, (int) ($type['client_count'] ?? 0), null, null]], null, 'A'.$row++);
            }
        }

        if (isset($results['booking_statuses']) && is_array($results['booking_statuses'])) {
            foreach ((array) ($results['booking_statuses']['statuses'] ?? []) as $status) {
                $sheet->fromArray([['Статусы записей', (string) ($status['label'] ?? '—'), (int) ($status['count'] ?? 0), null, null, null, null]], null, 'A'.$row++);
            }
        }

        if (isset($results['segments']) && is_array($results['segments'])) {
            foreach ((array) ($results['segments']['segments'] ?? []) as $segment) {
                $sheet->fromArray([['Категории клиентов', (string) ($segment['label'] ?? '—'), (int) ($segment['count'] ?? 0), null, null, $segment['percent'] ?? null, null]], null, 'A'.$row++);
            }
        }

        foreach (['scheduling' => 'Записи и визиты', 'finance' => 'Финансы'] as $key => $label) {
            if (! isset($results[$key]) || ! is_array($results[$key])) {
                continue;
            }
            foreach ($results[$key] as $metric => $value) {
                if (is_array($value)) {
                    continue;
                }
                $sheet->fromArray([[$label, self::humanMetric((string) $metric), $value, null, null, null, null]], null, 'A'.$row++);
            }
        }

        foreach (range('A', 'G') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        return response()->streamDownload(
            static function () use ($spreadsheet): void {
                (new Xlsx($spreadsheet))->save('php://output');
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private static function humanMetric(string $metric): string
    {
        return match ($metric) {
            'bookings' => 'Записи',
            'visits' => 'Визиты',
            'cancellations' => 'Отмены',
            'reschedules' => 'Переносы',
            'no_shows' => 'Неявки',
            'available' => 'Отчёт доступен',
            'currency' => 'Валюта',
            'revenue_minor' => 'Доход (минимальные единицы)',
            'receipt_count' => 'Количество платежей',
            'debt_minor' => 'Долг (минимальные единицы)',
            'realized_ltv_minor' => 'LTV (минимальные единицы)',
            'expenses_minor' => 'Расходы (минимальные единицы)',
            'cash_movement_minor' => 'Движение денежных средств (минимальные единицы)',
            default => $metric,
        };
    }
}
