<?php

namespace Tests\Feature\Sessions;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Infrastructure\Logging\RedactSensitiveLogData;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\UpdateSession;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

final class MedicalSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_staff_can_create_and_read_encrypted_session(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();

        $occurredAt = Carbon::parse('2026-01-15 10:30:00', 'Europe/Moscow');

        $command = new CreateSessionCommand(
            clientId: (int) $client->getKey(),
            specialistId: (int) $specialist->getKey(),
            occurredAt: $occurredAt,
            pain: 'Острая боль в пояснице, иррадиирующая в левую ногу.',
            tests: 'МРТ пояснично-крестцового отдела, тест на прямую ногу.',
            observations: 'Сглаженность лордоза, напряжение паравертебральных мышц.',
            rootCauseHypothesis: 'Дисфункция крестцово-подвздошного сочленения.',
            protocol: 'Мануальная терапия 5 сеансов, НПВС местно.',
            result: 'Уменьшение болевого синдрома на 70% после первого сеанса.',
        );

        $result = app(CreateSession::class)->handle($admin, $client, $command);

        self::assertInstanceOf(MedicalSessionData::class, $result);
        self::assertSame('Острая боль в пояснице, иррадиирующая в левую ногу.', $result->pain);
        self::assertSame('МРТ пояснично-крестцового отдела, тест на прямую ногу.', $result->tests);
        self::assertSame('Сглаженность лордоза, напряжение паравертебральных мышц.', $result->observations);
        self::assertSame('Дисфункция крестцово-подвздошного сочленения.', $result->rootCauseHypothesis);
        self::assertSame('Мануальная терапия 5 сеансов, НПВС местно.', $result->protocol);
        self::assertSame('Уменьшение болевого синдрома на 70% после первого сеанса.', $result->result);
        self::assertSame(1, $result->encryptionKeyVersion);
        self::assertSame((int) $client->getKey(), $result->clientId);
        self::assertSame((int) $specialist->getKey(), $result->specialistId);
        self::assertNull($result->bookingId);

        $rawRow = DB::table('medical_sessions')
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->first();

        self::assertNotNull($rawRow);
        self::assertStringNotContainsString('пояснице', (string) $rawRow->pain);
        self::assertStringNotContainsString('МРТ', (string) $rawRow->tests);
        self::assertStringNotContainsString('лордоза', (string) $rawRow->observations);
        self::assertStringNotContainsString('крестцово-подвздошного', (string) $rawRow->root_cause_hypothesis);
        self::assertStringNotContainsString('МАНУАЛЬНАЯ', (string) $rawRow->protocol);
        self::assertStringNotContainsString('синдром', (string) $rawRow->result);
        self::assertSame(1, (int) $rawRow->encryption_key_version);

        $retrieved = app(GetSession::class)->handle($admin, MedicalSession::findOrFail($result->id));

        self::assertNotNull($retrieved);
        self::assertSame('Острая боль в пояснице, иррадиирующая в левую ногу.', $retrieved->pain);
        self::assertSame('МРТ пояснично-крестцового отдела, тест на прямую ногу.', $retrieved->tests);
        self::assertSame('Дисфункция крестцово-подвздошного сочленения.', $retrieved->rootCauseHypothesis);
    }

    public function test_session_can_be_created_without_booking_and_booking_id_is_null(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();

        $command = new CreateSessionCommand(
            clientId: (int) $client->getKey(),
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::now('UTC'),
            pain: 'Ambient pain note',
        );

        $result = app(CreateSession::class)->handle($admin, $client, $command);

        self::assertNull($result->bookingId);
        $this->assertDatabaseHas('medical_sessions', [
            'id' => $result->id,
            'booking_id' => null,
        ]);
    }

    public function test_session_can_be_created_with_booking_link(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();

        $command = new CreateSessionCommand(
            clientId: (int) $client->getKey(),
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::now('UTC'),
            bookingId: (int) $booking->getKey(),
            pain: 'Связан с записью на приём.',
        );

        $result = app(CreateSession::class)->handle($admin, $client, $command);

        self::assertSame((int) $booking->getKey(), $result->bookingId);
    }

    public function test_create_rejects_same_org_booking_belonging_to_other_client(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();
        $otherClient = Client::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($otherClient)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();

        $command = new CreateSessionCommand(
            clientId: (int) $client->getKey(),
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::now('UTC'),
            bookingId: (int) $booking->getKey(),
            pain: 'Связан с чужой записью.',
        );

        $this->expectException(ValidationException::class);
        app(CreateSession::class)->handle($admin, $client, $command);
    }

    public function test_create_rejects_same_org_booking_belonging_to_other_specialist(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();
        $otherSpecialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($otherSpecialist)
            ->forService($service)
            ->create();

        $command = new CreateSessionCommand(
            clientId: (int) $client->getKey(),
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::now('UTC'),
            bookingId: (int) $booking->getKey(),
            pain: 'Связан с чужим специалистом.',
        );

        $this->expectException(ValidationException::class);
        app(CreateSession::class)->handle($admin, $client, $command);
    }

    public function test_cross_organization_cross_specialist_booking_is_rejected_at_application_layer(): void
    {
        [$orgA, $adminA, $clientA, $specialistA] = $this->setupOrganizationWithClientAndSpecialist();
        [$orgB, $adminB, $clientB, $specialistB] = $this->setupOrganizationWithClientAndSpecialist();
        app(OrganizationContext::class)->set($orgA);

        $command = new CreateSessionCommand(
            clientId: (int) $clientA->getKey(),
            specialistId: (int) $specialistB->getKey(),
            occurredAt: Carbon::now('UTC'),
            pain: 'Попытка использовать чужого специалиста.',
        );

        $this->expectException(ModelNotFoundException::class);
        app(CreateSession::class)->handle($adminA, $clientA, $command);
    }

    public function test_cross_organization_staff_cannot_read_session(): void
    {
        [$orgA, $adminA, $clientA, $specialistA] = $this->setupOrganizationWithClientAndSpecialist();
        [$orgB, $adminB] = $this->setupOrganizationWithClientAndSpecialist();
        app(OrganizationContext::class)->set($orgA);

        $session = $this->createSession($adminA, $clientA, $specialistA);

        app(OrganizationContext::class)->set($orgB);

        $this->expectException(AuthorizationException::class);
        app(GetSession::class)->handle($adminB, $session);
    }

    public function test_cross_organization_staff_cannot_update_session(): void
    {
        [$orgA, $adminA, $clientA, $specialistA] = $this->setupOrganizationWithClientAndSpecialist();
        [$orgB, $adminB] = $this->setupOrganizationWithClientAndSpecialist();
        app(OrganizationContext::class)->set($orgA);

        $session = $this->createSession($adminA, $clientA, $specialistA);

        app(OrganizationContext::class)->set($orgB);

        $this->expectException(AuthorizationException::class);
        app(UpdateSession::class)->handle($adminB, $session, new UpdateSessionCommand(pain: 'Взлом записи.'));
    }

    public function test_cross_organization_staff_cannot_create_session_for_other_org_client(): void
    {
        [$orgA, $adminA, $clientA, $specialistA] = $this->setupOrganizationWithClientAndSpecialist();
        [$orgB, $adminB] = $this->setupOrganizationWithClientAndSpecialist();
        app(OrganizationContext::class)->set($orgB);

        $this->expectException(AuthorizationException::class);
        app(CreateSession::class)->handle($adminB, $clientA, new CreateSessionCommand(
            clientId: (int) $clientA->getKey(),
            specialistId: (int) $specialistA->getKey(),
            occurredAt: Carbon::now('UTC'),
            pain: 'Атака по чужому клиенту.',
        ));
    }

    public function test_audit_events_for_session_contain_no_clinical_plaintext(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();

        $command = new CreateSessionCommand(
            clientId: (int) $client->getKey(),
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::now('UTC'),
            pain: 'Очень секретная находка: смещение позвонка L4.',
            result: 'Полное выздоровление после терапии.',
        );

        $result = app(CreateSession::class)->handle($admin, $client, $command);

        $audit = AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('target_type', MedicalSession::class)
            ->where('action', 'medical.session.created')
            ->first();

        self::assertNotNull($audit);
        self::assertSame('crm', $audit->metadata['source'] ?? null);
        self::assertSame(1, $audit->metadata['key_version'] ?? null);
        self::assertSame((int) $client->getKey(), $audit->metadata['client_id'] ?? null);
        self::assertSame((int) $specialist->getKey(), $audit->metadata['specialist_id'] ?? null);

        $metadataJson = json_encode($audit->metadata, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('секретная находка', (string) $metadataJson);
        self::assertStringNotContainsString('позвонка L4', (string) $metadataJson);
        self::assertStringNotContainsString('Выздоровление', (string) $metadataJson);

        app(UpdateSession::class)->handle($admin, MedicalSession::findOrFail($result->id), new UpdateSessionCommand(
            protocol: 'Расширенный протокол: добавлены процедуры.',
        ));

        $updateAudit = AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('target_type', MedicalSession::class)
            ->where('action', 'medical.session.updated')
            ->first();

        self::assertNotNull($updateAudit);
        self::assertSame('protocol', $updateAudit->metadata['updated_fields'] ?? '');

        $updateJson = json_encode($updateAudit->metadata, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('Расширенный протокол', (string) $updateJson);
        self::assertStringNotContainsString('процедуры', (string) $updateJson);
    }

    public function test_session_encryption_remains_independent_from_app_key(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();

        $session = $this->createSession($admin, $client, $specialist, pain: 'Секретная находка с выделенным ключом.');

        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $retrieved = app(GetSession::class)->handle($admin, $session);
        self::assertNotNull($retrieved);
        self::assertSame('Секретная находка с выделенным ключом.', $retrieved->pain);
    }

    public function test_occurred_at_is_canonical_timestamp_tz_persisted_utc(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();

        $occurredAt = Carbon::parse('2026-02-14 18:00:00', 'Asia/Yekaterinburg');

        $result = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            clientId: (int) $client->getKey(),
            specialistId: (int) $specialist->getKey(),
            occurredAt: $occurredAt,
            pain: 'Сеанс в Екатеринбурге冬天.',
        ));

        $raw = DB::table('medical_sessions')->where('id', $result->id)->first();
        self::assertNotNull($raw);
        $stored = Carbon::parse((string) $raw->occurred_at, 'UTC');
        self::assertSame($occurredAt->utc()->toISOString(), $stored->toISOString());
    }

    public function test_field_length_validation(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();

        $tooLong = str_repeat('а', 10001);

        $this->expectException(ValidationException::class);
        app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            clientId: (int) $client->getKey(),
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::now('UTC'),
            pain: $tooLong,
        ));
    }

    public function test_session_allows_inactive_specialist_for_retroactive_recording(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClientAndSpecialist();
        $inactiveSpecialist = Specialist::factory()->inactive()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        $result = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            clientId: (int) $client->getKey(),
            specialistId: (int) $inactiveSpecialist->getKey(),
            occurredAt: Carbon::parse('2025-12-01 09:00:00', 'UTC'),
            pain: 'Ретроактивная запись по неактивному специалисту.',
        ));

        self::assertSame((int) $inactiveSpecialist->getKey(), $result->specialistId);
    }

    public function test_sensitive_session_clinical_values_are_redacted_in_logs(): void
    {
        $processor = new RedactSensitiveLogData;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'testing',
            level: Level::Info,
            message: 'Processing medical session record',
            context: [
                'session_id' => 7,
                'medical_session_id' => 7,
                'pain' => 'Секретная находка: смещение L4.',
                'observations' => 'Сглаженность лордоза.',
                'root_cause_hypothesis' => 'Дисфункция КПС.',
                'tests' => 'МРТ 1.5Т без контраста.',
                'protocol' => 'Мануальная терапия 5 сеансов.',
                'result' => 'Улучшение на 70%.',
            ],
        );

        $redacted = $processor($record);

        // 'session' is already covered by the pre-existing sensitive key pattern
        // (it was present before M7B.1 for HTTP session privacy), so 'session_id'
        // and 'medical_session_id' keys are redacted by name here too.
        self::assertSame('[REDACTED]', $redacted->context['session_id']);
        self::assertSame('[REDACTED]', $redacted->context['medical_session_id']);
        self::assertSame('[REDACTED]', $redacted->context['pain']);
        self::assertSame('[REDACTED]', $redacted->context['observations']);
        self::assertSame('[REDACTED]', $redacted->context['root_cause_hypothesis']);
        // 'tests', 'protocol', 'result' are intentionally NOT added to the sensitive
        // key pattern (they commonly appear in non-medical operational logs), per
        // the M7B.1 plan redaction policy and amendment #8.
        self::assertSame('МРТ 1.5Т без контраста.', $redacted->context['tests']);
        self::assertSame('Мануальная терапия 5 сеансов.', $redacted->context['protocol']);
        self::assertSame('Улучшение на 70%.', $redacted->context['result']);
    }

    public function test_medical_sessions_table_does_not_have_anamnesis_column(): void
    {
        $columns = DB::connection()->getSchemaBuilder()->getColumnListing('medical_sessions');

        self::assertNotContains('anamnesis', $columns);
        self::assertContains('pain', $columns);
        self::assertContains('tests', $columns);
        self::assertContains('observations', $columns);
        self::assertContains('root_cause_hypothesis', $columns);
        self::assertContains('protocol', $columns);
        self::assertContains('result', $columns);
    }

    public function test_update_session_round_trip_preserves_structural_ownership(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();

        $session = $this->createSession($admin, $client, $specialist, pain: 'Первая запись боли.');

        $updated = app(UpdateSession::class)->handle($admin, $session, new UpdateSessionCommand(
            pain: 'Обновлённая запись боли.',
            protocol: 'Новый протокол.',
        ));

        self::assertSame($session->getKey(), $updated->id);
        self::assertSame((int) $client->getKey(), $updated->clientId);
        self::assertSame((int) $specialist->getKey(), $updated->specialistId);
        self::assertSame('Обновлённая запись боли.', $updated->pain);
        self::assertSame('Новый протокол.', $updated->protocol);

        $rawBeforeUpdate = DB::table('medical_sessions')->where('id', $session->getKey())->first();
        self::assertSame($rawBeforeUpdate->occurred_at, $rawBeforeUpdate->occurred_at); // immutability reference
        self::assertSame((int) $specialist->getKey(), (int) $rawBeforeUpdate->specialist_id);
        self::assertSame((int) $client->getKey(), (int) $rawBeforeUpdate->client_id);
    }

    /**
     * @return array{0: Organization, 1: User, 2: Client, 3: Specialist}
     */
    private function setupOrganizationWithClientAndSpecialist(): array
    {
        $organization = Organization::factory()->create();
        $organization->featureFlags()->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();

        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client, $specialist];
    }

    private function createSession(User $admin, Client $client, Specialist $specialist, ?string $pain = null): MedicalSession
    {
        $result = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            clientId: (int) $client->getKey(),
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::now('UTC'),
            pain: $pain ?? 'Запись боли для теста.',
        ));

        return MedicalSession::query()
            ->where('organization_id', (int) $result->organizationId)
            ->where('id', $result->id)
            ->firstOrFail();
    }
}
