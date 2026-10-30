<?php

namespace Tests\Feature;

use App\Filament\Pages\MedicalClinicalCatalogConfiguration;
use App\Filament\Resources\Clients\Resources\Sessions\Schemas\ClinicalTestProtocol;
use App\Models\User;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Application\ImportMedicalClinicalCatalogCsv;
use App\Modules\Sessions\Application\MedicalClinicalCatalog;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class MedicalClinicalCatalogConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_configure_tests_values_and_causes_and_session_options_use_them(): void
    {
        [$organization, $admin] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(MedicalClinicalCatalogConfiguration::class)
            ->assertActionExists('importCsv')
            ->fillForm([
                'tests' => [[
                    'key' => 'synthetic_balance',
                    'name' => 'Синтетический баланс',
                    'section' => 'Синтетика',
                    'is_active' => true,
                    'sort_order' => 1,
                    'values' => [
                        ['key' => 'normal', 'label' => 'Норма', 'is_abnormal' => false, 'is_active' => true, 'sort_order' => 1],
                        ['key' => 'abnormal', 'label' => 'Отклонение', 'is_abnormal' => true, 'is_active' => true, 'sort_order' => 2],
                    ],
                ]],
                'causes' => [[
                    'name' => 'Синтетическая причина',
                    'group_name' => 'QA',
                    'is_active' => true,
                    'sort_order' => 1,
                ]],
                'problem_tags' => [[
                    'name' => 'Синтетическая категория',
                    'group_name' => 'QA',
                    'is_active' => true,
                    'sort_order' => 1,
                ]],
            ])
            ->call('save')
            ->assertNotified('Каталог сохранён');

        $test = MedicalTestDefinition::query()->where('organization_id', $organization->getKey())->sole();
        self::assertSame('Синтетический баланс', $test->name);
        self::assertSame(2, MedicalTestValue::query()->where('medical_test_definition_id', $test->getKey())->count());
        self::assertSame('Синтетическая причина', MedicalCauseDefinition::query()->where('organization_id', $organization->getKey())->sole()->name);
        self::assertSame('Синтетическая категория', MedicalProblemTag::query()->where('organization_id', $organization->getKey())->sole()->name);

        $reloaded = Livewire::actingAs($admin)->test(MedicalClinicalCatalogConfiguration::class);
        $state = $reloaded->instance()->data;
        $tests = array_values(is_array($state['tests'] ?? null) ? $state['tests'] : []);
        $causes = array_values(is_array($state['causes'] ?? null) ? $state['causes'] : []);
        $problemTags = array_values(is_array($state['problem_tags'] ?? null) ? $state['problem_tags'] : []);
        self::assertSame('Синтетический баланс', $tests[0]['name'] ?? null);
        $values = array_values(is_array($tests[0]['values'] ?? null) ? $tests[0]['values'] : []);
        $abnormal = collect($values)->firstWhere('label', 'Отклонение');
        self::assertTrue((bool) ($abnormal['is_abnormal'] ?? false));
        self::assertSame('Синтетическая причина', $causes[0]['name'] ?? null);
        self::assertSame('Синтетическая категория', $problemTags[0]['name'] ?? null);
    }

    public function test_manager_can_import_tests_causes_and_problem_tags_from_human_labelled_csv(): void
    {
        [$organization, $admin] = $this->fixture();

        $testRows = app(ImportMedicalClinicalCatalogCsv::class)->handle(
            $admin,
            UploadedFile::fake()->createWithContent('tests.csv', implode("\n", [
                'Название теста;Раздел;Значение;Нарушение;Активен;Порядок теста;Порядок значения',
                'Баланс;Статика;Норма;нет;да;1;1',
                'Баланс;Статика;Отклонение;да;да;1;2',
            ])),
            'tests',
        );
        $causeRows = app(ImportMedicalClinicalCatalogCsv::class)->handle(
            $admin,
            UploadedFile::fake()->createWithContent('causes.csv', "Название;Группа;Активна;Порядок\nМышечный дисбаланс;QA;да;1\n"),
            'causes',
        );
        $tagRows = app(ImportMedicalClinicalCatalogCsv::class)->handle(
            $admin,
            UploadedFile::fake()->createWithContent('tags.csv', "Название;Группа;Активна;Порядок\nПоясница;QA;да;1\n"),
            'problem_tags',
        );

        self::assertSame(2, $testRows);
        self::assertSame(1, $causeRows);
        self::assertSame(1, $tagRows);
        $test = MedicalTestDefinition::query()->where('organization_id', $organization->getKey())->sole();
        self::assertSame('Баланс', $test->name);
        self::assertSame(2, $test->values()->count());
        self::assertTrue((bool) $test->values()->where('label', 'Отклонение')->value('is_abnormal'));
        self::assertSame('Мышечный дисбаланс', MedicalCauseDefinition::query()->where('organization_id', $organization->getKey())->sole()->name);
        self::assertSame('Поясница', MedicalProblemTag::query()->where('organization_id', $organization->getKey())->sole()->name);

        $testKey = $test->key;
        app(ImportMedicalClinicalCatalogCsv::class)->handle(
            $admin,
            UploadedFile::fake()->createWithContent('tests.csv', "Название теста;Значение\nБаланс;Норма\nБаланс;Отклонение\n"),
            'tests',
        );
        self::assertSame($testKey, $test->refresh()->key);
    }

    public function test_manager_can_import_the_same_catalog_contract_from_xlsx(): void
    {
        [$organization, $admin] = $this->fixture();
        $path = tempnam(sys_get_temp_dir(), 'medical-catalog-');
        self::assertIsString($path);

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->fromArray([
                ['Название теста', 'Раздел', 'Значение', 'Нарушение', 'Активен', 'Порядок теста', 'Порядок значения'],
                ['Синтетический тест', 'Баланс', 'Норма', 'нет', 'да', 1, 1],
                ['Синтетический тест', 'Баланс', 'Отклонение', 'да', 'да', 1, 2],
            ]);
            (new Xlsx($spreadsheet))->save($path);

            $rows = app(ImportMedicalClinicalCatalogCsv::class)->handle(
                $admin,
                new UploadedFile($path, 'tests.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', UPLOAD_ERR_OK, true),
                'tests',
            );

            self::assertSame(2, $rows);
            $test = MedicalTestDefinition::query()->where('organization_id', $organization->getKey())->sole();
            self::assertSame(2, $test->values()->count());
            self::assertTrue((bool) $test->values()->where('label', 'Отклонение')->value('is_abnormal'));
        } finally {
            unlink($path);
        }
    }

    public function test_protocol_lists_large_active_catalog_and_keeps_historical_inactive_tests(): void
    {
        [$organization] = $this->fixture();
        $tests = [];
        for ($index = 1; $index <= 60; $index++) {
            $test = MedicalTestDefinition::query()->create([
                'organization_id' => $organization->getKey(),
                'key' => 'synthetic_test_'.$index,
                'name' => 'Синтетический тест '.$index,
                'section' => $index <= 30 ? 'Раздел A' : 'Раздел B',
                'is_active' => true,
                'sort_order' => $index,
            ]);
            $normal = MedicalTestValue::query()->create([
                'organization_id' => $organization->getKey(),
                'medical_test_definition_id' => $test->getKey(),
                'key' => 'normal_'.$index,
                'label' => 'Норма',
                'is_abnormal' => false,
                'is_active' => true,
                'sort_order' => 1,
            ]);
            $abnormal = MedicalTestValue::query()->create([
                'organization_id' => $organization->getKey(),
                'medical_test_definition_id' => $test->getKey(),
                'key' => 'abnormal_'.$index,
                'label' => 'Отклонение',
                'is_abnormal' => true,
                'is_active' => true,
                'sort_order' => 2,
            ]);
            $tests[] = [$test, $normal, $abnormal];
        }

        app(OrganizationContext::class)->set($organization);
        $rows = app(MedicalClinicalCatalog::class)->testProtocolRows((int) $organization->getKey());
        self::assertCount(60, $rows);
        self::assertSame('Синтетический тест 1', $rows->first()->name);

        $counts = app(MedicalClinicalCatalog::class)->abnormalCounts((int) $organization->getKey(), [
            ['test_id' => $tests[0][0]->getKey(), 'before_value_id' => $tests[0][2]->getKey(), 'after_value_id' => $tests[0][1]->getKey()],
            ['test_id' => $tests[1][0]->getKey(), 'before_value_id' => $tests[1][1]->getKey(), 'after_value_id' => $tests[1][2]->getKey()],
        ]);
        self::assertSame(['before' => 1, 'after' => 1], $counts);

        $tests[0][0]->forceFill(['is_active' => false])->save();
        $state = ClinicalTestProtocol::stateFor([
            ['test_id' => $tests[0][0]->getKey(), 'before_value_id' => $tests[0][2]->getKey(), 'after_value_id' => null, 'cause_ids' => []],
        ]);
        self::assertSame($tests[0][0]->getKey(), $state[0]['test_id']);
        self::assertSame($tests[0][2]->getKey(), $state[0]['before_value_id']);
    }

    /** @return array{Organization, User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    private function resolveFilamentContext(User $user, Organization $organization): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }
}
