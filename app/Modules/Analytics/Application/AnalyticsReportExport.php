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
            default => $metric,
        };
    }
}
