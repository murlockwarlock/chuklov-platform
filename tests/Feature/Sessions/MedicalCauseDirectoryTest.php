<?php

namespace Tests\Feature\Sessions;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Application\ApplyMedicalCauseToTests;
use App\Modules\Sessions\Application\MedicalCauseDirectory;
use App\Modules\Sessions\Application\MergeMedicalCauses;
use App\Modules\Sessions\Application\SaveMedicalCauseDefinition;
use App\Modules\Sessions\Application\SaveMedicalTestCauseLinks;
use App\Modules\Sessions\Domain\Enums\MedicalSessionStatus;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionRootCause;
use App\Modules\Sessions\Domain\Models\MedicalSessionTestResult;
use App\Modules\Sessions\Domain\Models\MedicalTestCauseLink;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MedicalCauseDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_uses_russian_name_synonyms_group_and_tooltip_and_rejects_duplicates(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();

        $cause = app(SaveMedicalCauseDefinition::class)->handle($admin, [
            'name' => 'Тестовая причина каталога',
            'synonyms' => ['Синоним каталога'],
            'group_name' => 'Тестовая группа',
            'tooltip' => 'Подсказка для поиска',
        ]);

        self::assertSame((int) $cause->getKey(), app(MedicalCauseDirectory::class)
            ->search((int) $organization->getKey(), 'синоним каталога')
            ->first()?->getKey());
        self::assertSame((int) $cause->getKey(), app(MedicalCauseDirectory::class)
            ->search((int) $organization->getKey(), 'тестовая группа')
            ->first()?->getKey());
        self::assertSame((int) $cause->getKey(), app(MedicalCauseDirectory::class)
            ->search((int) $organization->getKey(), 'подсказка для поиска')
            ->first()?->getKey());

        $this->expectException(ValidationException::class);
        app(SaveMedicalCauseDefinition::class)->handle($admin, [
            'name' => 'Другая тестовая причина',
            'synonyms' => ['Синоним каталога'],
        ]);
    }

    public function test_merge_repoints_legacy_history_and_links_and_apply_is_idempotent(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $source = app(SaveMedicalCauseDefinition::class)->handle($admin, [
            'name' => 'Тестовая причина источник',
            'synonyms' => ['Старый синоним'],
        ]);
        $target = app(SaveMedicalCauseDefinition::class)->handle($admin, [
            'name' => 'Тестовая причина канон',
        ]);

        $test = new MedicalTestDefinition;
        $test->forceFill([
            'organization_id' => $organization->getKey(),
            'key' => 'test_cause_directory',
            'name' => 'Тестовая строка каталога',
            'is_active' => true,
            'sort_order' => 1,
        ])->save();
        $session = $this->session($organization, $admin);
        $result = new MedicalSessionTestResult;
        $result->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $session->client_id,
            'medical_session_id' => $session->getKey(),
            'medical_test_definition_id' => $test->getKey(),
            'structured_payload' => app(MedicalEncryptorInterface::class)->encryptField(
                (int) $organization->getKey(),
                json_encode(['cause_ids' => [$source->getKey()], 'primary_cause_id' => $source->getKey()], JSON_THROW_ON_ERROR),
                1,
            ),
            'structured_payload_key_version' => 1,
        ])->save();

        $now = now();
        DB::table('medical_session_causes')->insert([
            'organization_id' => $organization->getKey(),
            'client_id' => $session->client_id,
            'medical_session_id' => $session->getKey(),
            'medical_cause_definition_id' => $source->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('medical_session_test_causes')->insert([
            'organization_id' => $organization->getKey(),
            'medical_session_test_result_id' => $result->getKey(),
            'medical_cause_definition_id' => $source->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $rootCause = new MedicalSessionRootCause;
        $rootCause->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $session->client_id,
            'medical_session_id' => $session->getKey(),
            'medical_cause_definition_id' => $source->getKey(),
            'encryption_key_version' => 1,
            'sort_order' => 0,
        ])->save();
        MedicalTestCauseLink::query()->create([
            'organization_id' => $organization->getKey(),
            'medical_test_definition_id' => $test->getKey(),
            'medical_cause_definition_id' => $source->getKey(),
            'scope_key' => 'row-a',
            'is_active' => true,
        ]);

        $merged = app(MergeMedicalCauses::class)->handle($admin, (int) $source->getKey(), (int) $target->getKey());

        self::assertSame((int) $target->getKey(), (int) $merged->getKey());
        self::assertSame((int) $target->getKey(), app(MedicalCauseDirectory::class)
            ->canonical((int) $organization->getKey(), (int) $source->getKey())->getKey());
        self::assertFalse((bool) $source->fresh()->is_active);
        self::assertDatabaseHas('medical_session_causes', [
            'organization_id' => $organization->getKey(),
            'medical_session_id' => $session->getKey(),
            'medical_cause_definition_id' => $target->getKey(),
        ]);
        self::assertDatabaseHas('medical_session_test_causes', [
            'organization_id' => $organization->getKey(),
            'medical_session_test_result_id' => $result->getKey(),
            'medical_cause_definition_id' => $target->getKey(),
        ]);
        self::assertSame((int) $target->getKey(), (int) $rootCause->fresh()->medical_cause_definition_id);
        $rewrittenPayload = json_decode((string) app(MedicalEncryptorInterface::class)->decryptField(
            (int) $organization->getKey(),
            (string) $result->fresh()->structured_payload,
            1,
        ), true, 12, JSON_THROW_ON_ERROR);
        self::assertSame([(int) $target->getKey()], $rewrittenPayload['cause_ids']);
        self::assertSame((int) $target->getKey(), (int) $rewrittenPayload['primary_cause_id']);
        self::assertDatabaseHas('medical_test_cause_links', [
            'organization_id' => $organization->getKey(),
            'medical_test_definition_id' => $test->getKey(),
            'medical_cause_definition_id' => $target->getKey(),
            'scope_key' => 'row-a',
        ]);

        $secondMerge = app(MergeMedicalCauses::class)->handle($admin, (int) $source->getKey(), (int) $target->getKey());
        self::assertSame((int) $target->getKey(), (int) $secondMerge->getKey());

        $secondTest = $test->replicate();
        $secondTest->forceFill(['key' => 'test_cause_directory_two'])->save();
        app(ApplyMedicalCauseToTests::class)->handle($admin, (int) $target->getKey(), [(int) $secondTest->getKey()], 'row-b');
        app(ApplyMedicalCauseToTests::class)->handle($admin, (int) $target->getKey(), [(int) $secondTest->getKey()], 'row-b');
        self::assertSame(1, MedicalTestCauseLink::query()
            ->where('organization_id', $organization->getKey())
            ->where('medical_test_definition_id', $secondTest->getKey())
            ->where('medical_cause_definition_id', $target->getKey())
            ->where('scope_key', 'row-b')
            ->count());

        app(SaveMedicalTestCauseLinks::class)->handle($admin, $secondTest, [(int) $target->getKey()], 'row-c');
        self::assertDatabaseHas('medical_test_cause_links', [
            'medical_test_definition_id' => $secondTest->getKey(),
            'medical_cause_definition_id' => $target->getKey(),
            'scope_key' => 'row-c',
            'is_active' => true,
        ]);
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationAndAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    private function session(Organization $organization, User $admin): MedicalSession
    {
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $session = new MedicalSession;
        $session->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'specialist_id' => $specialist->getKey(),
            'status' => MedicalSessionStatus::Draft,
            'encryption_key_version' => 1,
            'occurred_at' => now(),
        ])->save();

        return $session;
    }
}
