<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\MedicalProfiles\Application\MedicalFrequencyPeriodCatalog;
use App\Modules\MedicalProfiles\Application\MedicalProfilePainReader;
use App\Modules\MedicalProfiles\Application\SaveMedicalFrequencyPeriods;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfilePain;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Application\GenerateSessionResult;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\MedicalSessionPainInitializer;
use App\Modules\Sessions\Application\UpdateSession;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MedicalPainFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_pains_initialize_first_session_and_carry_forward_with_previous_after(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();

        app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
            pains: [
                ['name' => 'Шея', 'score' => 7],
                ['name' => 'Поясница', 'score' => 3],
            ],
            painsProvided: true,
        ));

        $profile = app(GetMedicalProfile::class)->handle($admin, $client);
        self::assertNotNull($profile);
        self::assertSame(['Шея', 'Поясница'], array_map(static fn ($pain): string => $pain->name, $profile->pains));
        self::assertSame([7, 3], array_map(static fn ($pain): ?int => $pain->score, $profile->pains));

        $first = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-10-01 09:00:00', 'UTC'),
        ));
        self::assertSame([7, 3], array_map(static fn ($pain): ?int => $pain->beforeScore, $first->pains));
        app(MedicalSessionPainInitializer::class)->initialize(
            MedicalSession::findOrFail($first->id),
            $first->encryptionKeyVersion,
        );
        self::assertSame(2, MedicalSession::findOrFail($first->id)->pains()->count());

        $firstPainRows = array_map(static function ($pain): array {
            $row = $pain->toArray();
            $row['frequency_mode'] = 'count';
            $row['frequency_count'] = 2;
            $row['frequency_period_unit'] = 'week';
            $row['time_of_day'] = ['morning', 'evening'];
            $row['before_score'] = $pain->beforeScore;
            $row['after_score'] = $pain->name === 'Шея' ? 0 : 2;

            return $row;
        }, $first->pains);
        app(UpdateSession::class)->handle($admin, MedicalSession::findOrFail($first->id), UpdateSessionCommand::fromArray([
            'pain' => null,
            'tests' => null,
            'observations' => null,
            'root_cause_hypothesis' => null,
            'protocol' => null,
            'result' => null,
            'pains' => $firstPainRows,
        ]));

        $next = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-10-08 09:00:00', 'UTC'),
        ));
        self::assertSame(['Шея', 'Поясница'], array_map(static fn ($pain): string => $pain->name, $next->pains));
        self::assertSame([null, null], array_map(static fn ($pain): ?int => $pain->beforeScore, $next->pains));
        self::assertSame([0, 2], array_map(static fn ($pain): ?int => $pain->previousAfterScore, $next->pains));
        self::assertSame([['morning', 'evening'], ['morning', 'evening']], array_map(static fn ($pain): array => $pain->timeOfDay, $next->pains));

        $reloaded = app(GetSession::class)->handle($admin, MedicalSession::findOrFail($next->id), $client);
        self::assertNotNull($reloaded);
        self::assertCount(2, $reloaded->pains);
        self::assertSame(0, $reloaded->pains[0]->previousAfterScore);
        self::assertSame($organization->getKey(), $client->organization_id);
    }

    public function test_frequency_periods_are_owner_editable_and_used_by_session_pains(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();

        app(SaveMedicalFrequencyPeriods::class)->handle($admin, [
            ['key' => 'week', 'label' => 'Неделя', 'is_active' => true, 'sort_order' => 1],
            ['key' => 'fortnight', 'label' => 'Две недели', 'is_active' => true, 'sort_order' => 2],
            ['key' => 'year', 'label' => 'Год', 'is_active' => false, 'sort_order' => 3],
        ]);

        $options = app(MedicalFrequencyPeriodCatalog::class)->options((int) $organization->getKey());
        self::assertSame('Неделя', $options['week']);
        self::assertSame('Две недели', $options['fortnight']);
        self::assertArrayNotHasKey('year', $options);

        $session = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-10-20 09:00:00', 'UTC'),
            pains: [[
                'name' => 'Плечо',
                'frequency_mode' => 'count',
                'frequency_count' => 2,
                'frequency_period_unit' => 'fortnight',
                'time_of_day' => ['day'],
                'before_score' => 6,
            ]],
        ));

        self::assertSame('fortnight', $session->pains[0]->frequencyPeriodUnit);
    }

    public function test_problem_tags_are_preserved_from_profile_to_session_and_next_session(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $tag = MedicalProblemTag::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Шейный отдел',
            'group_name' => 'Синтетика',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $updatedProfile = app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
            pains: [['name' => 'Шея', 'score' => 6, 'tag_ids' => [$tag->getKey()]]],
            painsProvided: true,
        ));
        self::assertSame([(int) $tag->getKey()], $updatedProfile->pains[0]->tagIds);
        self::assertDatabaseHas('medical_profile_pain_tags', ['medical_problem_tag_id' => $tag->getKey()]);
        $persistedPain = MedicalProfilePain::query()->where('organization_id', $organization->getKey())->sole();
        self::assertSame(1, $persistedPain->problemTags()->count());
        self::assertSame([(int) $tag->getKey()], $persistedPain->problemTags()->get()->modelKeys());
        $persistedPain->load('problemTags');
        self::assertSame([(int) $tag->getKey()], $persistedPain->problemTags->modelKeys());
        self::assertSame([(int) $tag->getKey()], app(MedicalProfilePainReader::class)->forProfile($client->medicalProfile()->firstOrFail())[0]->tagIds);

        $first = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-10-01 09:00:00', 'UTC'),
        ));
        self::assertSame([(int) $tag->getKey()], $first->pains[0]->tagIds);

        $next = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-10-08 09:00:00', 'UTC'),
        ));
        self::assertSame([(int) $tag->getKey()], $next->pains[0]->tagIds);
    }

    public function test_profile_history_rows_and_session_observation_fields_are_encrypted_and_reloadable(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();

        $profile = app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
            historyEntries: [
                ['type' => 'operation', 'year' => 2019, 'description' => 'Артроскопия колена'],
                ['type' => 'injury', 'year' => 2022, 'description' => 'Растяжение голеностопа'],
            ],
            historyEntriesProvided: true,
        ));

        self::assertSame(['operation', 'injury'], array_map(static fn ($entry): string => $entry->type, $profile->historyEntries));
        self::assertSame(['Артроскопия колена', 'Растяжение голеностопа'], array_map(static fn ($entry): string => $entry->description, $profile->historyEntries));

        $rawHistory =
            DB::table('medical_profile_history_entries')->where('organization_id', $organization->getKey())->get();
        self::assertCount(2, $rawHistory);
        self::assertStringNotContainsString('Артроскопия', (string) $rawHistory[0]->description);

        $session = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-10-22 09:00:00', 'UTC'),
            subjectiveFeelings: 'Стало легче двигаться',
            visualPostureDiagnostics: 'Плечи выровнены',
        ));

        self::assertSame('Стало легче двигаться', $session->subjectiveFeelings);
        self::assertSame('Плечи выровнены', $session->visualPostureDiagnostics);
        $reloaded = app(GetSession::class)->handle($admin, MedicalSession::findOrFail($session->id), $client);
        self::assertSame('Стало легче двигаться', $reloaded?->subjectiveFeelings);
        self::assertSame('Плечи выровнены', $reloaded?->visualPostureDiagnostics);

        $generated = app(GenerateSessionResult::class)->handle($admin, MedicalSession::findOrFail($session->id), $client);
        self::assertStringContainsString('Боли: нет заполненных оценок', $generated);
        self::assertStringContainsString('Субъективные ощущения: Стало легче двигаться', $generated);
        self::assertStringContainsString('Визуальная диагностика осанки: Плечи выровнены', $generated);
    }

    public function test_configured_tests_values_causes_and_root_causes_round_trip_per_session(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $test = MedicalTestDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'key' => 'step_test',
            'name' => 'Патрон шага',
            'section' => 'Ноги',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $normal = MedicalTestValue::query()->create([
            'organization_id' => $organization->getKey(),
            'medical_test_definition_id' => $test->getKey(),
            'key' => 'normal',
            'label' => 'Нормотонус',
            'is_abnormal' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $high = MedicalTestValue::query()->create([
            'organization_id' => $organization->getKey(),
            'medical_test_definition_id' => $test->getKey(),
            'key' => 'high',
            'label' => 'Гипертонус',
            'is_abnormal' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);
        $cause = MedicalCauseDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Мышечный дисбаланс',
            'group_name' => 'Мышцы',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $session = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-10-23 09:00:00', 'UTC'),
            testResults: [[
                'test_id' => $test->getKey(),
                'before_value_id' => $high->getKey(),
                'after_value_id' => $normal->getKey(),
                'cause_ids' => [$cause->getKey()],
            ]],
            rootCauses: [['cause_id' => $cause->getKey(), 'free_text' => 'Подтверждено на осмотре']],
        ));

        self::assertSame('Гипертонус', $session->testResults[0]->beforeLabel);
        self::assertTrue($session->testResults[0]->beforeAbnormal);
        self::assertFalse($session->testResults[0]->afterAbnormal);
        self::assertSame('Мышечный дисбаланс', $session->testResults[0]->causes[0]['name']);
        self::assertSame('Подтверждено на осмотре', $session->rootCauses[0]->freeText);

        $generated = app(GenerateSessionResult::class)->handle($admin, MedicalSession::findOrFail($session->id), $client);
        self::assertStringContainsString('Нарушения по тестам: было 1, стало 0.', $generated);

        $reloaded = app(GetSession::class)->handle($admin, MedicalSession::findOrFail($session->id), $client);
        self::assertSame($session->testResults[0]->toArray(), $reloaded?->testResults[0]->toArray());
        self::assertSame($session->rootCauses[0]->toArray(), $reloaded?->rootCauses[0]->toArray());
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
