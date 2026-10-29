<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\AnalyticsReportExport;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

final class AnalyticsReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_report_remains_a_valid_summary_workbook_with_human_headers(): void
    {
        [, $admin] = $this->fixture();

        $workbook = $this->readWorkbook(app(AnalyticsReportExport::class)->download($admin, []));

        self::assertSame(['Сводка'], $workbook->getSheetNames());
        self::assertSame(['Параметр', 'Значение', 'Валюта'], $workbook->getActiveSheet()->toArray()[0]);
        self::assertStringNotContainsString('TRUE', implode('|', $workbook->getActiveSheet()->toArray()[0]));
    }

    public function test_business_dimensions_use_semantic_sheets_and_preserve_unicode(): void
    {
        [, $admin] = $this->fixture();

        $workbook = $this->readWorkbook(app(AnalyticsReportExport::class)->download($admin, [
            'pain' => [
                'client_count' => 2,
                'session_count' => 3,
                'problems' => [[
                    'name' => 'Шея и плечевой пояс',
                    'group' => 'Опорно-двигательная система',
                    'client_count' => 2,
                    'average_before' => 7.5,
                    'average_after' => 3.25,
                    'average_reduction' => 4.25,
                    'average_reduction_percent' => 56.67,
                ]],
            ],
            'demographics' => [
                'client_count' => 2,
                'age' => [['label' => '30–44', 'count' => 2, 'percent' => 100]],
            ],
            'services' => [
                'visit_count' => 4,
                'services' => [['name' => 'Массаж', 'client_count' => 2, 'visit_count' => 4]],
            ],
            'scheduling' => [
                'bookings' => 4,
                'visits' => 3,
                'cancellations' => 1,
                'reschedules' => 0,
                'no_shows' => 0,
            ],
            'finance' => [
                'available' => true,
                'currency' => 'USD',
                'revenue_display' => '100.00 USD',
                'average_receipt_display' => '50.00 USD',
                'realized_ltv_display' => '100.00 USD',
                'debt_display' => '0.00 USD',
                'expenses_display' => '25.00 USD',
                'cash_movement_display' => '75.00 USD',
            ],
        ], metadata: [
            'period' => '01.10.2026 — 31.10.2026',
            'client' => 'Все клиенты',
            'filters' => ['Показатели: Боли', 'Категория проблемы: Шея'],
            'timezone' => 'Asia/Almaty',
            'currency' => 'USD',
        ]));

        self::assertSame(['Сводка', 'Демография', 'Боли', 'Услуги', 'Финансы'], $workbook->getSheetNames());
        self::assertSame(
            ['Проблема', 'Группа', 'Клиентов', 'Среднее до', 'Среднее после', 'Снижение', 'Снижение, %'],
            $workbook->getSheetByName('Боли')?->toArray()[0],
        );
        self::assertContains(
            ['Шея и плечевой пояс', 'Опорно-двигательная система', '2', '7.5', '3.25', '4.25', '56.67'],
            $workbook->getSheetByName('Боли')?->toArray(),
        );
        self::assertSame(['Параметр', 'Значение', 'Количество', 'Доля, %'], $workbook->getSheetByName('Демография')?->toArray()[0]);
        self::assertSame(['Услуга', 'Клиентов', 'Визитов'], $workbook->getSheetByName('Услуги')?->toArray()[0]);
        self::assertSame(['Показатель', 'Значение', 'Валюта'], $workbook->getSheetByName('Финансы')?->toArray()[0]);
        self::assertContains(['Средний чек', '50.00 USD', 'USD'], $workbook->getSheetByName('Финансы')?->toArray());
        self::assertContains(['Средний чек', '50.00 USD', 'USD'], $workbook->getSheetByName('Сводка')?->toArray());
        self::assertNotContains('После', $workbook->getSheetByName('Демография')?->toArray()[0] ?? []);
        self::assertNotContains('До', $workbook->getSheetByName('Услуги')?->toArray()[0] ?? []);
        self::assertStringNotContainsString('TRUE', $this->workbookText($workbook));
    }

    public function test_sources_and_finance_accounts_have_human_columns(): void
    {
        [, $admin] = $this->fixture();

        $workbook = $this->readWorkbook(app(AnalyticsReportExport::class)->download($admin, [
            'acquisition' => [
                'new_clients' => 4,
                'sources' => [['label' => 'Instagram', 'count' => 2]],
            ],
            'finance' => [
                'available' => true,
                'currency' => 'USD',
                'revenue_display' => '100.00 USD',
                'accounts' => [[
                    'label' => 'Основная касса',
                    'account_type' => 'cash',
                    'currency' => 'USD',
                    'movement_display' => '-25.00 USD',
                ]],
            ],
        ]));

        self::assertSame(['Источник', 'Новые клиенты', 'Доля, %'], $workbook->getSheetByName('Источники')?->toArray()[0]);
        self::assertContains(['Instagram', '2', '50'], $workbook->getSheetByName('Источники')?->toArray());
        self::assertContains(['Счёт', 'Тип', 'Валюта счёта', 'Движение за период', 'Базовая валюта'], $workbook->getSheetByName('Финансы')?->toArray());
        self::assertContains(['Основная касса', 'Касса', 'USD', '-25.00 USD', 'USD'], $workbook->getSheetByName('Финансы')?->toArray());
    }

    private function readWorkbook(StreamedResponse $response): Spreadsheet
    {
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        self::assertIsString($content);

        $path = tempnam(sys_get_temp_dir(), 'analytics-export-');
        self::assertIsString($path);

        try {
            file_put_contents($path, $content);

            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    private function workbookText(Spreadsheet $workbook): string
    {
        $values = [];
        foreach ($workbook->getAllSheets() as $sheet) {
            foreach ($sheet->toArray() as $row) {
                foreach ($row as $value) {
                    $values[] = (string) $value;
                }
            }
        }

        return implode('|', $values);
    }

    /** @return array{0: Organization, 1: User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }
}
