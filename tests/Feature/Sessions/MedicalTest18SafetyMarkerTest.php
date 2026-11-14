<?php

namespace Tests\Feature\Sessions;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Application\ClearMedicalTest18SafetyMarker;
use App\Modules\Sessions\Application\MedicalSafetyMarkerReader;
use App\Modules\Sessions\Application\MedicalTest18SafetyMarker;
use App\Modules\Sessions\Application\UpdateMedicalTest18SafetyMarkerText;
use App\Modules\Sessions\Domain\Enums\MedicalSessionStatus;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionSafetyMarker;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MedicalTest18SafetyMarkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_yes_creates_encrypted_client_and_session_markers_and_marker_persists_for_later_sessions(): void
    {
        [$organization, $admin, $client] = $this->organizationClientAndAdmin();
        $session = $this->medicalSession($organization, $client);

        $marker = app(MedicalTest18SafetyMarker::class)->handleFromResult($admin, $session, [
            'before' => ['rows' => [['answer' => 'Да']]],
        ]);

        self::assertNotNull($marker);
        self::assertDatabaseHas('medical_client_safety_markers', [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'marker_key' => MedicalTest18SafetyMarker::MARKER_KEY,
            'is_active' => true,
        ]);
        self::assertDatabaseHas('medical_session_safety_markers', [
            'organization_id' => $organization->getKey(),
            'medical_session_id' => $session->getKey(),
            'marker_key' => MedicalTest18SafetyMarker::MARKER_KEY,
        ]);

        $rawClientText = DB::table('medical_client_safety_markers')->value('marker_text');
        self::assertNotSame(MedicalTest18SafetyMarker::DEFAULT_TEXT, $rawClientText);
        self::assertSame(MedicalTest18SafetyMarker::DEFAULT_TEXT, app(MedicalSafetyMarkerReader::class)->forClient($admin, $client)[0]['marker_text']);

        $laterSession = $this->medicalSession($organization, $client);
        self::assertSame(MedicalTest18SafetyMarker::DEFAULT_TEXT, app(MedicalSafetyMarkerReader::class)->forClient($admin, $client)[0]['marker_text']);
        self::assertSame(MedicalTest18SafetyMarker::DEFAULT_TEXT, app(MedicalSafetyMarkerReader::class)->forSession($admin, $laterSession)[0]['marker_text']);
    }

    public function test_clear_requires_date_and_reason_and_text_edit_is_authorized_and_encrypted(): void
    {
        [$organization, $admin, $client] = $this->organizationClientAndAdmin();
        $session = $this->medicalSession($organization, $client);
        app(MedicalTest18SafetyMarker::class)->handle($admin, $session, true);

        $this->expectException(ValidationException::class);
        app(ClearMedicalTest18SafetyMarker::class)->handle($admin, $client, Carbon::now('UTC'), '');
    }

    public function test_clear_records_reason_and_date_and_edit_does_not_change_session_evidence(): void
    {
        [$organization, $admin, $client] = $this->organizationClientAndAdmin();
        $session = $this->medicalSession($organization, $client);
        app(MedicalTest18SafetyMarker::class)->handle($admin, $session, true);

        app(UpdateMedicalTest18SafetyMarkerText::class)->handle($admin, $client, 'Текст маркера для текущего отображения.');
        self::assertSame('Текст маркера для текущего отображения.', app(MedicalSafetyMarkerReader::class)->forClient($admin, $client)[0]['marker_text']);

        $clearedAt = Carbon::parse('2026-11-15 12:30:00', 'UTC');
        app(ClearMedicalTest18SafetyMarker::class)->handle($admin, $client, $clearedAt, 'Проверка завершена.');

        self::assertSame([], app(MedicalSafetyMarkerReader::class)->forClient($admin, $client));
        self::assertSame(1, MedicalSessionSafetyMarker::query()
            ->where('organization_id', $organization->getKey())
            ->where('medical_session_id', $session->getKey())
            ->count());
        self::assertSame(MedicalTest18SafetyMarker::DEFAULT_TEXT, app(MedicalSafetyMarkerReader::class)->forSession($admin, $session)[0]['marker_text']);
        self::assertSame('Проверка завершена.', app(MedicalSafetyMarkerReader::class)->historyForClient($admin, $client)[0]['clear_reason']);
        $clientMarker = DB::table('medical_client_safety_markers')->first();
        self::assertFalse((bool) $clientMarker->is_active);
        self::assertSame('2026-11-15 12:30:00', Carbon::parse($clientMarker->cleared_at)->utc()->format('Y-m-d H:i:s'));
        self::assertNotSame('Проверка завершена.', $clientMarker->clear_reason);
    }

    public function test_edited_marker_text_is_used_for_a_later_session_evidence(): void
    {
        [$organization, $admin, $client] = $this->organizationClientAndAdmin();
        $session = $this->medicalSession($organization, $client);
        app(MedicalTest18SafetyMarker::class)->handle($admin, $session, true);
        app(UpdateMedicalTest18SafetyMarkerText::class)->handle($admin, $client, 'Текущий текст предупреждения.');

        $laterSession = $this->medicalSession($organization, $client);
        app(MedicalTest18SafetyMarker::class)->handleFromResult($admin, $laterSession, [
            'after' => ['rows' => [['answer' => 'Да']]],
        ]);

        self::assertSame('Текущий текст предупреждения.', app(MedicalSafetyMarkerReader::class)->forSession($admin, $laterSession)[0]['marker_text']);
    }

    /** @return array{0: Organization, 1: User, 2: Client} */
    private function organizationClientAndAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client];
    }

    private function medicalSession(Organization $organization, Client $client): MedicalSession
    {
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
