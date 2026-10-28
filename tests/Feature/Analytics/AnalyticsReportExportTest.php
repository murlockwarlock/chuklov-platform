<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\AnalyticsReportExport;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

final class AnalyticsReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_account_movements_are_present_in_the_excel_report(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

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

        ob_start();
        $response->sendContent();
        $binary = ob_get_clean();
        self::assertIsString($binary);

        $path = tempnam(sys_get_temp_dir(), 'analytics-export-');
        self::assertIsString($path);

        try {
            file_put_contents($path, $binary);
            $rows = IOFactory::load($path)->getActiveSheet()->toArray();
        } finally {
            unlink($path);
        }

        self::assertContains(['Финансы', 'Основная касса', '-25.00 USD', 'Касса', 'USD', null, null], $rows);
    }
}
