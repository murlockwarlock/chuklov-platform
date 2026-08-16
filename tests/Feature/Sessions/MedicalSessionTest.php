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
        // Encryption-at-rest assertions for every clinical field use the exact
        // plaintext casing so a substring match definitively proves ciphertext.
        self::assertNotSame('Острая боль в пояснице, иррадиирующая в левую ногу.', (string) $rawRow->pain);
        self::assertStringNotContainsString('пояснице', (string) $rawRow->pain);
        self::assertNotSame('МРТ пояснично-крестцового отдела, тест на прямую ногу.', (string) $rawRow->tests);
        self::assertStringNotContainsString('МРТ', (string) $rawRow->tests);
        self::assertNotSame('Сглаженность лордоза, напряжение паравертебральных мышц.', (string) $rawRow->observations);
        self::assertStringNotContainsString('лордоза', (string) $rawRow->observations);
        self::assertNotSame('Дисфункция крестцово-подвздошного сочленения.', (string) $rawRow->root_cause_hypothesis);
        self::assertStringNotContainsString('крестцово-подвздошного', (string) $rawRow->root_cause_hypothesis);
        self::assertNotSame('Мануальная терапия 5 сеансов, НПВС местно.', (string) $rawRow->protocol);
        self::assertStringNotContainsString('Мануальная', (string) $rawRow->protocol);
        self::assertNotSame('Уменьшение болевого синдрома на 70% после первого сеанса.', (string) $rawRow->result);
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
            specialistId: (int) $specialistB->getKey(),
            occurredAt: Carbon::now('UTC'),
            pain: 'Попытка использовать чужого специалиста.',
        );

        $this->expectException(ValidationException::class);
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
        app(UpdateSession::class)->handle($adminB, $session, new UpdateSessionCommand(
            pain: 'Взлом записи.',
            tests: null,
            observations: null,
            rootCauseHypothesis: null,
            protocol: null,
            result: null,
        ));
    }

    public function test_cross_organization_staff_cannot_create_session_for_other_org_client(): void
    {
        [$orgA, $adminA, $clientA, $specialistA] = $this->setupOrganizationWithClientAndSpecialist();
        [$orgB, $adminB] = $this->setupOrganizationWithClientAndSpecialist();
        app(OrganizationContext::class)->set($orgB);

        $this->expectException(AuthorizationException::class);
        app(CreateSession::class)->handle($adminB, $clientA, new CreateSessionCommand(
            specialistId: (int) $specialistA->getKey(),
            occurredAt: Carbon::now('UTC'),
            pain: 'Атака по чужому клиенту.',
        ));
    }

    public function test_audit_events_for_session_contain_no_clinical_plaintext(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();

        $command = new CreateSessionCommand(
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
            pain: 'Очень секретная находка: смещение позвонка L4.',
            tests: null,
            observations: null,
            rootCauseHypothesis: null,
            protocol: 'Расширенный протокол: добавлены процедуры.',
            result: 'Полное выздоровление после терапии.',
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
            specialistId: (int) $specialist->getKey(),
            occurredAt: $occurredAt,
            pain: 'Сеанс в Екатеринбурге冬天.',
        ));

        $raw = DB::table('medical_sessions')->where('id', $result->id)->first();
        self::assertNotNull($raw);
        $stored = Carbon::parse((string) $raw->occurred_at, 'UTC');
        self::assertSame($occurredAt->utc()->toISOString(), $stored->toISOString());
    }

    public function test_create_session_command_rejects_missing_occurred_at(): void
    {
        $this->expectException(ValidationException::class);
        CreateSessionCommand::fromArray([
            'specialist_id' => 1,
            'pain' => 'some pain',
        ]);
    }

    public function test_create_session_command_rejects_empty_occurred_at(): void
    {
        $this->expectException(ValidationException::class);
        CreateSessionCommand::fromArray([
            'specialist_id' => 1,
            'occurred_at' => '',
            'pain' => 'some pain',
        ]);
    }

    public function test_create_session_command_rejects_invalid_non_empty_occurred_at(): void
    {
        $this->expectException(ValidationException::class);
        CreateSessionCommand::fromArray([
            'specialist_id' => 1,
            'occurred_at' => 'not-a-real-date-zzz-random-garbage-12345',
            'pain' => 'some pain',
        ]);
    }

    public function test_create_session_command_accepts_string_and_datetime_occurred_at_inputs(): void
    {
        $fromString = CreateSessionCommand::fromArray([
            'specialist_id' => 1,
            'occurred_at' => '2026-03-15 12:00:00',
            'pain' => 'p',
        ]);
        self::assertSame('2026-03-15T12:00:00+00:00', $fromString->occurredAtUtc()->format(\DateTimeInterface::ATOM));

        $fromDateTime = CreateSessionCommand::fromArray([
            'specialist_id' => 1,
            'occurred_at' => Carbon::parse('2026-04-15 13:00:00', 'Europe/Moscow'),
        ]);
        self::assertSame('2026-04-15T10:00:00+00:00', $fromDateTime->occurredAtUtc()->format(\DateTimeInterface::ATOM));
    }

    public function test_field_length_validation(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();

        $tooLong = str_repeat('а', 10001);

        $this->expectException(ValidationException::class);
        app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
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

        // All six Session clinical field context keys must be redacted; no clinical
        // plaintext may remain. 'session' / 'medical' / 'root_cause_hypothesis'
        // are covered by the pre-existing general sensitive key pattern or via the
        // dedicated Session clinical exact-key matcher; 'pain', 'tests',
        // 'observations', 'protocol', 'result' are covered by the Session clinical
        // exact-key matcher.
        self::assertSame('[REDACTED]', $redacted->context['session_id']);
        self::assertSame('[REDACTED]', $redacted->context['medical_session_id']);
        self::assertSame('[REDACTED]', $redacted->context['pain']);
        self::assertSame('[REDACTED]', $redacted->context['observations']);
        self::assertSame('[REDACTED]', $redacted->context['root_cause_hypothesis']);
        self::assertSame('[REDACTED]', $redacted->context['tests']);
        self::assertSame('[REDACTED]', $redacted->context['protocol']);
        self::assertSame('[REDACTED]', $redacted->context['result']);
    }

    public function test_session_clinical_message_text_is_redacted_only_when_labeled(): void
    {
        $processor = new RedactSensitiveLogData;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'testing',
            level: Level::Info,
            message: 'medical_session.pain=Секретная находка medical_session.tests: МРТ без контраста medical_session.protocol=Мануальная терапия medical_session.result=Улучшение на 70% medical_session.observations=Сглаженность Лордоза medical_session.root_cause_hypothesis=Дисфункция КПС clinical.pain=Дополнительная находка',
            context: [],
        );

        $redacted = $processor($record);

        self::assertSame('medical_session.pain=[REDACTED] medical_session.tests=[REDACTED] medical_session.protocol=[REDACTED] medical_session.result=[REDACTED] medical_session.observations=[REDACTED] medical_session.root_cause_hypothesis=[REDACTED] clinical.pain=[REDACTED]', $redacted->message);
        self::assertStringNotContainsString('Секретная', $redacted->message);
        self::assertStringNotContainsString('МРТ', $redacted->message);
        self::assertStringNotContainsString('Мануальная', $redacted->message);
        self::assertStringNotContainsString('Улучшение', $redacted->message);
        self::assertStringNotContainsString('Лордоза', $redacted->message);
        self::assertStringNotContainsString('Дисфункция', $redacted->message);
        self::assertStringNotContainsString('Дополнительная', $redacted->message);
    }

    public function test_unlabeled_operational_test_result_sessions_words_remain_unredacted(): void
    {
        $processor = new RedactSensitiveLogData;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'testing',
            level: Level::Info,
            message: 'Running test suite. Result: 42 tests passed. Sessions flushed to storage. Protocol version 2 negotiated. pain: user reported soreness. tests=521 protocol:mqtt result=ok observations logged.',
            context: [],
        );

        $redacted = $processor($record);

        self::assertStringContainsString('Running test suite. Result: 42 tests passed.', $redacted->message);
        self::assertStringContainsString('Sessions flushed to storage.', $redacted->message);
        self::assertStringContainsString('Protocol version 2 negotiated.', $redacted->message);
        self::assertStringContainsString('pain: user reported soreness.', $redacted->message);
        self::assertStringContainsString('tests=521', $redacted->message);
        self::assertStringContainsString('protocol:mqtt', $redacted->message);
        self::assertStringContainsString('result=ok', $redacted->message);
        self::assertStringContainsString('observations logged.', $redacted->message);
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

    public function test_update_session_with_full_snapshot_preserves_unchanged_fields_and_truthfully_records_audit(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();

        $createResult = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-01-10 09:00:00', 'UTC'),
            pain: 'Исходная запись боли.',
            tests: 'Исходные тесты.',
            observations: 'Исходные наблюдения.',
            rootCauseHypothesis: 'Исходная гипотеза.',
            protocol: 'Исходный протокол.',
            result: 'Исходный результат.',
        ));

        // Full snapshot identical to current persisted state should yield no changed fields.
        app(UpdateSession::class)->handle($admin, MedicalSession::findOrFail($createResult->id), new UpdateSessionCommand(
            pain: 'Исходная запись боли.',
            tests: 'Исходные тесты.',
            observations: 'Исходные наблюдения.',
            rootCauseHypothesis: 'Исходная гипотеза.',
            protocol: 'Исходный протокол.',
            result: 'Исходный результат.',
        ));

        $noChangeAudit = AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('target_type', MedicalSession::class)
            ->where('action', 'medical.session.updated')
            ->orderByDesc('id')
            ->first();
        self::assertNotNull($noChangeAudit);
        self::assertSame('', $noChangeAudit->metadata['updated_fields'] ?? null);

        // Full snapshot where only pain actually changes: every other field must be preserved.
        $updated = app(UpdateSession::class)->handle($admin, MedicalSession::findOrFail($createResult->id), new UpdateSessionCommand(
            pain: 'Обновлённая запись боли.',
            tests: 'Исходные тесты.',
            observations: 'Исходные наблюдения.',
            rootCauseHypothesis: 'Исходная гипотеза.',
            protocol: 'Исходный протокол.',
            result: 'Исходный результат.',
        ));

        self::assertSame('Обновлённая запись боли.', $updated->pain);
        self::assertSame('Исходные тесты.', $updated->tests);
        self::assertSame('Исходные наблюдения.', $updated->observations);
        self::assertSame('Исходная гипотеза.', $updated->rootCauseHypothesis);
        self::assertSame('Исходный протокол.', $updated->protocol);
        self::assertSame('Исходный результат.', $updated->result);

        $painOnlyAudit = AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('target_type', MedicalSession::class)
            ->where('action', 'medical.session.updated')
            ->orderByDesc('id')
            ->first();
        self::assertNotNull($painOnlyAudit);
        self::assertSame('pain', $painOnlyAudit->metadata['updated_fields'] ?? null);

        $painAuditJson = json_encode($painOnlyAudit->metadata, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('Обновлённая запись боли', (string) $painAuditJson);
    }

    public function test_update_session_explicit_clear_is_recorded_audit_truthfully(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();

        $createResult = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-01-10 09:00:00', 'UTC'),
            pain: 'Боль до очищения.',
            tests: 'Тесты до очищения.',
            observations: 'Наблюдения до очищения.',
            rootCauseHypothesis: 'Гипотеза до очищения.',
            protocol: 'Протокол до очищения.',
            result: 'Результат до очищения.',
        ));

        $updated = app(UpdateSession::class)->handle($admin, MedicalSession::findOrFail($createResult->id), new UpdateSessionCommand(
            pain: null,
            tests: 'Тесты до очищения.',
            observations: 'Наблюдения до очищения.',
            rootCauseHypothesis: 'Гипотеза до очищения.',
            protocol: 'Протокол до очищения.',
            result: 'Результат до очищения.',
        ));

        self::assertNull($updated->pain);
        self::assertSame('Тесты до очищения.', $updated->tests);

        $clearAudit = AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('target_type', MedicalSession::class)
            ->where('action', 'medical.session.updated')
            ->orderByDesc('id')
            ->first();
        self::assertNotNull($clearAudit);
        self::assertSame('pain', $clearAudit->metadata['updated_fields'] ?? null);
    }

    public function test_update_session_command_from_array_rejects_missing_clinical_field_keys(): void
    {
        $this->expectException(ValidationException::class);
        UpdateSessionCommand::fromArray([
            'pain' => 'только боль',
        ]);
    }

    public function test_update_session_command_from_array_rejects_non_string_clinical_value_instead_of_clearing(): void
    {
        $this->expectException(ValidationException::class);
        UpdateSessionCommand::fromArray([
            'pain' => 42,
            'tests' => null,
            'observations' => null,
            'root_cause_hypothesis' => null,
            'protocol' => null,
            'result' => null,
        ]);
    }

    public function test_update_session_command_from_array_rejects_array_clinical_value_instead_of_clearing(): void
    {
        $this->expectException(ValidationException::class);
        UpdateSessionCommand::fromArray([
            'pain' => 'настоящая боль',
            'tests' => ['unexpected', 'array'],
            'observations' => null,
            'root_cause_hypothesis' => null,
            'protocol' => null,
            'result' => null,
        ]);
    }

    public function test_update_session_command_from_array_accepts_explicit_null_clears(): void
    {
        $command = UpdateSessionCommand::fromArray([
            'pain' => null,
            'tests' => null,
            'observations' => null,
            'root_cause_hypothesis' => null,
            'protocol' => null,
            'result' => null,
        ]);

        self::assertNull($command->pain);
        self::assertNull($command->tests);
        self::assertNull($command->observations);
        self::assertNull($command->rootCauseHypothesis);
        self::assertNull($command->protocol);
        self::assertNull($command->result);
    }

    public function test_update_session_preserves_structural_ownership_and_occurred_at_immutability(): void
    {
        [$organization, $admin, $client, $specialist] = $this->setupOrganizationWithClientAndSpecialist();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();

        $occurredAt = Carbon::parse('2026-01-10 09:00:00', 'UTC');

        $createResult = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: $occurredAt,
            bookingId: (int) $booking->getKey(),
            pain: 'Первая запись боли.',
            tests: 'Тесты.',
            observations: 'Наблюдения.',
            rootCauseHypothesis: 'Гипотеза.',
            protocol: 'Протокол.',
            result: 'Результат.',
        ));

        // Capture persisted structural/time values BEFORE the update.
        $before = DB::table('medical_sessions')->where('id', $createResult->id)->first();
        self::assertNotNull($before);

        $updated = app(UpdateSession::class)->handle($admin, MedicalSession::findOrFail($createResult->id), new UpdateSessionCommand(
            pain: 'Обновлённая запись боли.',
            tests: 'Тесты.',
            observations: 'Наблюдения.',
            rootCauseHypothesis: 'Гипотеза.',
            protocol: 'Новый протокол.',
            result: 'Результат.',
        ));

        $after = DB::table('medical_sessions')->where('id', $createResult->id)->first();
        self::assertNotNull($after);

        // Structural ownership and occurred_at MUST be immutable under UpdateSession.
        self::assertSame((int) $before->organization_id, (int) $after->organization_id);
        self::assertSame((int) $before->client_id, (int) $after->client_id);
        self::assertSame((int) $before->specialist_id, (int) $after->specialist_id);
        self::assertSame((int) $before->booking_id, (int) $after->booking_id);
        self::assertSame($before->occurred_at, $after->occurred_at);

        // And the returned DTO agrees.
        self::assertSame((int) $before->organization_id, $updated->organizationId);
        self::assertSame((int) $before->client_id, $updated->clientId);
        self::assertSame((int) $before->specialist_id, $updated->specialistId);
        self::assertSame((int) $before->booking_id, $updated->bookingId);
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
