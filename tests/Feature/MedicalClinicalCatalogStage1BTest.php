<?php

namespace Tests\Feature;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Models\User;
use App\Modules\Sessions\Application\MedicalClinicalCatalog;
use App\Modules\Sessions\Application\MedicalStage1BCatalogPreset;
use App\Modules\Sessions\Application\SaveMedicalClinicalCatalog;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestCauseLink;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MedicalClinicalCatalogStage1BTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_save_the_full_stage_1b_catalog_and_search_specialized_tests(): void
    {
        [$organization, $admin] = $this->fixture();
        $cause = MedicalCauseDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Причина для настройки каталога',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $definitions = MedicalStage1BCatalogPreset::definitions();
        $definitions[0]['cause_ids'] = [$cause->getKey()];

        app(SaveMedicalClinicalCatalog::class)->handle($admin, $definitions, [$cause->toArray()]);

        $catalog = app(MedicalClinicalCatalog::class);
        self::assertCount(31, $catalog->catalogRows((int) $organization->getKey()));
        self::assertSame([
            '1', '2', '3', '4', '5', '6', '7', '9', '25', '31.1',
        ], $catalog->defaultSessionSelection((int) $organization->getKey()));
        self::assertArrayHasKey(
            (int) MedicalTestDefinition::query()->where('organization_id', $organization->getKey())->where('key', 'medical_test_012')->value('id'),
            $catalog->specializedTestOptions((int) $organization->getKey(), 'lumbar'),
        );

        $test = MedicalTestDefinition::query()
            ->where('organization_id', $organization->getKey())
            ->where('key', 'medical_test_001')
            ->sole();
        self::assertSame('T1', $test->test_type);
        self::assertSame('main', $test->catalog_set);
        self::assertSame('standing_r', $test->catalogConfiguration()['rows'][0]['key']);
        self::assertTrue(MedicalTestCauseLink::query()
            ->where('organization_id', $organization->getKey())
            ->where('medical_test_definition_id', $test->getKey())
            ->where('medical_cause_definition_id', $cause->getKey())
            ->whereNull('scope_key')
            ->where('is_active', true)
            ->exists());
    }

    public function test_catalog_rejects_unknown_types(): void
    {
        [, $admin] = $this->fixture();
        $invalid = MedicalStage1BCatalogPreset::definitions()[0];
        $invalid['type'] = 'T99';

        try {
            app(SaveMedicalClinicalCatalog::class)->handle($admin, [$invalid], []);
            self::fail('An unknown clinical type must be rejected.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    public function test_catalog_rejects_foreign_allowed_causes(): void
    {
        [$organization, $admin] = $this->fixture();
        $foreignOrganization = Organization::factory()->create();
        $foreignCause = MedicalCauseDefinition::query()->create([
            'organization_id' => $foreignOrganization->getKey(),
            'name' => 'Причина другой организации',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $valid = MedicalStage1BCatalogPreset::definitions()[0];
        $valid['cause_ids'] = [$foreignCause->getKey()];
        try {
            app(SaveMedicalClinicalCatalog::class)->handle($admin, [$valid], []);
            self::fail('A cause from another organization must be rejected.');
        } catch (ValidationException) {
            self::assertFalse(MedicalTestDefinition::query()->where('organization_id', $organization->getKey())->exists());
        }
    }

    public function test_stage_1b_catalog_accepts_an_empty_allowed_cause_list(): void
    {
        [$organization, $admin] = $this->fixture();
        $definition = MedicalStage1BCatalogPreset::definitions()[30];
        $definition['cause_ids'] = [];

        app(SaveMedicalClinicalCatalog::class)->handle($admin, [$definition], []);

        $test = MedicalTestDefinition::query()
            ->where('organization_id', $organization->getKey())
            ->where('key', 'medical_test_031')
            ->sole();
        self::assertSame([], $test->causeLinks()->where('is_active', true)->pluck('medical_cause_definition_id')->all());
    }

    /** @return array{Organization, User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }
}
