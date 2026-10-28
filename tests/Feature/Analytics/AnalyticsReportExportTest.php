<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\AnalyticsReportExport;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

final class AnalyticsReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_report_remains_a_valid_download_with_human_headers(): void
    {
        [, $admin] = $this->fixture();

        $response = app(AnalyticsReportExport::class)->download($admin, []);
        $rows = $this->readRows($response);

        self::assertSame([
            ['Раздел', 'Показатель', 'Значение', 'Группа', 'До', 'После', 'Снижение'],
        ], $rows);
    }

    public function test_unicode_and_multiple_rows_are_preserved_in_the_excel_report(): void
    {
        [, $admin] = $this->fixture();

        $response = app(AnalyticsReportExport::class)->download($admin, [
            'pain' => [
                'problems' => [
                    [
                        'name' => 'Шея и плечевой пояс',
                        'group' => 'Опорно-двигательная система',
                        'client_count' => 2,
                        'average_before' => 7.5,
                        'average_after' => 3.25,
                        'average_reduction' => 4.25,
                    ],
                    [
                        'name' => '腰部',
                        'group' => null,
                        'client_count' => 1,
                        'average_before' => null,
                        'average_after' => 0,
                        'average_reduction' => null,
                    ],
                ],
            ],
        ]);
        $rows = $this->readRows($response);

        self::assertCount(3, $rows);
        self::assertContains([
            'Проблемы и динамика боли',
            'Шея и плечевой пояс',
            '2',
            'Опорно-двигательная система',
            '7.5',
            '3.25',
            '4.25',
        ], $rows);
        self::assertSame([
            'Проблемы и динамика боли',
            '腰部',
            '1',
            '—',
            null,
            '0',
            null,
        ], $rows[2]);
    }

    public function test_finance_account_movements_are_present_in_the_excel_report(): void
    {
        [, $admin] = $this->fixture();

        $response = app(AnalyticsReportExport::class)->download($admin, [
            'finance' => [
                'available' => true,
                'currency' => 'USD',
                'revenue_minor' => '10000',
                'accounts' => [[
                    'label' => 'Основная касса',
                    'account_type' => 'cash',
                    'currency' => 'USD',
                    'movement_minor' => '-2500',
                ]],
            ],
        ]);

        $rows = $this->readRows($response);

        self::assertContains(['Финансы', 'Основная касса', '-25.00 USD', 'Касса', 'USD', null, null], $rows);
    }

    /** @return array<array<int, mixed>> */
    private function readRows(StreamedResponse $response): array
    {
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        self::assertIsString($content);

        $path = tempnam(sys_get_temp_dir(), 'analytics-export-');
        self::assertIsString($path);

        try {
            file_put_contents($path, $content);

            return IOFactory::load($path)->getActiveSheet()->toArray();
        } finally {
            unlink($path);
        }
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
