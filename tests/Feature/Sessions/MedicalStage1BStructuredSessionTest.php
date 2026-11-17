<?php

namespace Tests\Feature\Sessions;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalClientRootCause;
use App\Modules\Sessions\Domain\Models\MedicalClientRootCauseHistory;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestCauseLink;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MedicalStage1BStructuredSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_before_after_payload_is_encrypted_and_survives_reload_with_legacy_values(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $definition = MedicalTestDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'key' => 'medical_test_structured_roundtrip',
            'name' => 'Тест структурированного результата',
            'section' => 'Основные тесты',
            'test_type' => 'T1',
            'catalog_config' => [
                'version' => 1,
                'rows' => [['key' => 'shoulder', 'label' => 'Плечо', 'side' => 'R']],
                'rules' => ['index_violation' => [1, 2, 3]],
            ],
            'catalog_set' => 'main',
            'default_in_session' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $beforeValue = MedicalTestValue::query()->create([
            'organization_id' => $organization->getKey(),
            'medical_test_definition_id' => $definition->getKey(),
            'key' => 'violation',
            'label' => 'Нарушение',
            'is_abnormal' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $afterValue = MedicalTestValue::query()->create([
            'organization_id' => $organization->getKey(),
            'medical_test_definition_id' => $definition->getKey(),
            'key' => 'normal',
            'label' => 'Норма',
            'is_abnormal' => false,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $session = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-11-15 14:00:00', 'UTC'),
            testResults: [[
                'test_id' => $definition->getKey(),
                'before_value_id' => $beforeValue->getKey(),
                'after_value_id' => $afterValue->getKey(),
                'structured' => [
                    'before' => ['rows' => [['key' => 'shoulder', 'side' => 'R', 'index' => 2]]],
                    'after' => ['rows' => [['key' => 'shoulder', 'side' => 'R', 'index' => 0]]],
                ],
            ]],
        ));

        $raw = DB::table('medical_session_test_results')
            ->where('medical_session_id', $session->id)
            ->sole();
        self::assertNotNull($raw->structured_payload);
        self::assertStringNotContainsString('shoulder', (string) $raw->structured_payload);
        self::assertSame(1, (int) $raw->before_violation_count);
        self::assertSame(0, (int) $raw->after_violation_count);

        $reloaded = app(GetSession::class)->handle(
            $admin,
            MedicalSession::findOrFail($session->id),
            $client,
        );

        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->testResults);
        self::assertSame((int) $beforeValue->getKey(), $reloaded->testResults[0]->beforeValueId);
        self::assertSame((int) $afterValue->getKey(), $reloaded->testResults[0]->afterValueId);
        self::assertSame(1, $reloaded->testResults[0]->beforeViolationCount);
        self::assertSame(0, $reloaded->testResults[0]->afterViolationCount);
        self::assertSame(1, $reloaded->testResults[0]->structuredPayload['evaluation']['before']);
        self::assertSame(0, $reloaded->testResults[0]->structuredPayload['evaluation']['after']);
        self::assertSame('T1', $reloaded->testResults[0]->structuredPayload['type']);
    }

    public function test_t13_general_index_and_measurement_payload_are_encrypted_and_survive_reload(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $definition = MedicalTestDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'key' => 'medical_test_structured_measurement',
            'name' => 'Ортопедическая проба',
            'section' => 'Специализированные тесты',
            'test_type' => 'T13',
            'catalog_config' => [
                'version' => 1,
                'rows' => [['key' => 'otto', 'label' => 'Отто', 'side' => 'R', 'unit_options' => ['cm'], 'phase_options' => ['flexion', 'extension']]],
                'measurement_units' => ['cm'],
                'movement_phases' => ['flexion', 'extension'],
                'rules' => ['index_violation' => [1, 2, 3], 'classification_enabled' => false],
            ],
            'catalog_set' => 'specialized',
            'default_in_session' => false,
            'is_active' => true,
            'sort_order' => 32,
        ]);

        $session = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-11-15 15:00:00', 'UTC'),
            testResults: [[
                'test_id' => $definition->getKey(),
                'structured' => [
                    'general_index' => ['before' => 2, 'after' => 0],
                    'before' => ['rows' => [['key' => 'otto', 'side' => 'R', 'unit' => 'cm', 'measurement' => 30, 'movement_phase' => 'flexion', 'index' => 0]]],
                    'after' => ['rows' => [['key' => 'otto', 'side' => 'R', 'unit' => 'cm', 'measurement' => 31, 'movement_phase' => 'extension', 'index' => 0]]],
                ],
            ]],
        ));

        $raw = DB::table('medical_session_test_results')->where('medical_session_id', $session->id)->sole();
        self::assertStringNotContainsString('general_index', (string) $raw->structured_payload);
        self::assertSame(1, (int) $raw->before_violation_count);
        self::assertSame(0, (int) $raw->after_violation_count);

        $reloaded = app(GetSession::class)->handle($admin, MedicalSession::findOrFail($session->id), $client);

        self::assertNotNull($reloaded);
        self::assertSame('T13', $reloaded->testResults[0]->structuredPayload['type']);
        self::assertSame(2, $reloaded->testResults[0]->structuredPayload['general_index']['before']);
        self::assertSame(0, $reloaded->testResults[0]->structuredPayload['general_index']['after']);
        self::assertSame(1, $reloaded->testResults[0]->structuredPayload['evaluation']['before']);
    }

    public function test_multiple_main_causes_complex_case_and_client_root_cause_history_are_preserved(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $definition = MedicalTestDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'key' => 'medical_test_complex_case',
            'name' => 'Тест сложного случая',
            'section' => 'Основные тесты',
            'test_type' => 'T1',
            'catalog_config' => [
                'version' => 1,
                'rows' => [['key' => 'region', 'label' => 'Регион', 'side' => 'R']],
                'rules' => ['index_violation' => [1, 2, 3]],
            ],
            'catalog_set' => 'main',
            'default_in_session' => false,
            'is_active' => true,
            'sort_order' => 40,
        ]);
        $causes = collect(range(1, 3))->map(function (int $number) use ($organization): MedicalCauseDefinition {
            return MedicalCauseDefinition::query()->create([
                'organization_id' => $organization->getKey(),
                'name' => 'Причина сложного случая '.$number,
                'is_active' => true,
                'moderation_status' => MedicalCauseDefinition::MODERATION_ACTIVE,
                'sort_order' => $number,
            ]);
        });
        foreach ($causes as $cause) {
            MedicalTestCauseLink::query()->create([
                'organization_id' => $organization->getKey(),
                'medical_test_definition_id' => $definition->getKey(),
                'medical_cause_definition_id' => $cause->getKey(),
                'scope_key' => null,
                'is_active' => true,
            ]);
        }

        $session = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-11-15 16:00:00', 'UTC'),
            rootCauses: [[
                'cause_id' => $causes[0]->getKey(),
                'status' => 'в работе',
                'region' => 'Поясница',
                'is_primary' => true,
                'influences' => [$causes[1]->getKey()],
            ]],
            testResults: [[
                'test_id' => $definition->getKey(),
                'structured' => [
                    'before' => ['rows' => [[
                        'key' => 'region',
                        'side' => 'R',
                        'index' => 2,
                        'cause_ids' => $causes->pluck('id')->all(),
                        'primary_cause_ids' => $causes->pluck('id')->all(),
                    ]]],
                    'after' => ['rows' => [['key' => 'region', 'side' => 'R', 'index' => 0]]],
                ],
            ]],
        ));

        $result = DB::table('medical_session_test_results')->where('medical_session_id', $session->id)->sole();
        self::assertTrue((bool) $result->is_complex_case);
        self::assertSame(1, MedicalClientRootCause::query()->where('client_id', $client->getKey())->count());
        self::assertSame('в работе', MedicalClientRootCause::query()->where('client_id', $client->getKey())->sole()->status);
        self::assertNotSame('Поясница', (string) MedicalClientRootCause::query()->where('client_id', $client->getKey())->sole()->region);
        self::assertSame(1, MedicalClientRootCauseHistory::query()->where('client_id', $client->getKey())->count());
    }

    /** @return array{Organization, User, Client, Specialist} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client, $specialist];
    }
}
