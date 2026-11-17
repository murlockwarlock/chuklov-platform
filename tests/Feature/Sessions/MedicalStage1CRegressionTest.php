<?php

namespace Tests\Feature\Sessions;

use App\Models\User;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Application\SendMedicalAudioToClient;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Contracts\NotificationChannel;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Application\GenerateClientMedicalPdf;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\LinkSessionAttachment;
use App\Modules\Sessions\Application\MedicalAnthropometryReader;
use App\Modules\Sessions\Application\MedicalMeasurementCalculator;
use App\Modules\Sessions\Application\SaveMedicalAnthropometryMeasurement;
use App\Modules\Sessions\Application\UpdateSession;
use App\Modules\Sessions\Application\UploadSessionPhoto;
use App\Modules\Sessions\Domain\Enums\MedicalSessionAttachmentGallery;
use App\Modules\Sessions\Domain\Models\MedicalAnthropometricMeasurement;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PrinsFrank\PdfParser\PdfParser;
use Tests\TestCase;

final class MedicalStage1CRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_vitals_and_anthropometry_calculations_are_stored_encrypted_and_reloaded(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();

        $created = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC'),
            measurements: [
                'before' => ['vitals' => ['sys' => '120', 'dia' => '80', 'pulse' => '72', 'pi' => '2.10', 'spo2' => '98']],
                'after_enabled' => true,
                'after' => ['vitals' => ['sys' => '118', 'dia' => '78', 'pulse' => '70', 'pi' => '2.50', 'spo2' => '99']],
            ],
        ));

        $rawSession = DB::table('medical_sessions')->where('id', $created->id)->sole();
        self::assertNotNull($rawSession->measurements);
        self::assertNotSame('', (string) $rawSession->measurements);

        $reloaded = app(GetSession::class)->handle($admin, MedicalSession::findOrFail($created->id), $client);

        self::assertNotNull($reloaded);
        self::assertSame(40, $reloaded->measurements['before']['vitals']['pd']);
        self::assertSame(93, $reloaded->measurements['before']['vitals']['map']);
        self::assertSame(40, $reloaded->measurements['after']['vitals']['pd']);
        self::assertSame(91, $reloaded->measurements['after']['vitals']['map']);
        self::assertSame(72, $reloaded->measurements['before']['vitals']['pulse']);
        self::assertSame(99, $reloaded->measurements['after']['vitals']['spo2']);

        $measurement = app(SaveMedicalAnthropometryMeasurement::class)->handle(
            $admin,
            $client,
            ['height_cm' => '180', 'weight_kg' => '81', 'impedance' => '520'],
            CarbonImmutable::parse('2026-11-17 10:05:00', 'UTC'),
        );

        self::assertInstanceOf(MedicalAnthropometricMeasurement::class, $measurement);
        self::assertStringNotContainsString('180', $measurement->payload);
        self::assertSame(25.0, (float) app(MedicalAnthropometryReader::class)->values($measurement->fresh())['bmi']);
        self::assertSame(1, MedicalAnthropometricMeasurement::query()->where('client_id', $client->getKey())->count());

        $calculator = app(MedicalMeasurementCalculator::class);
        $this->expectException(ValidationException::class);
        $calculator->normalizeVitals(['spo2' => 101]);
    }

    public function test_session_update_preserves_measurements_and_reopens_from_persisted_state(): void
    {
        [, $admin, $client, $specialist] = $this->fixture();
        $created = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-11-17 11:00:00', 'UTC'),
            result: 'Сохранённый результат сеанса',
            therapistNotes: 'ВНУТРЕННЯЯ_ЗАМЕТКА_НЕ_ДЛЯ_PDF',
            measurements: [
                'before' => ['vitals' => ['sys' => 120, 'dia' => 80]],
                'after_enabled' => false,
            ],
        ));

        app(UpdateSession::class)->handle(
            $admin,
            MedicalSession::findOrFail($created->id),
            new UpdateSessionCommand(
                pain: null,
                tests: null,
                observations: null,
                rootCauseHypothesis: null,
                protocol: null,
                result: 'Изменённый результат сеанса',
                measurements: [
                    'before' => ['vitals' => ['sys' => 121, 'dia' => 81]],
                    'after_enabled' => true,
                    'after' => ['vitals' => ['sys' => 119, 'dia' => 79]],
                ],
                measurementsProvided: true,
                therapistNotes: 'ВНУТРЕННЯЯ_ЗАМЕТКА_НЕ_ДЛЯ_PDF',
                therapistNotesProvided: true,
            ),
            $client,
        );

        $reopened = app(GetSession::class)->handle($admin, MedicalSession::findOrFail($created->id), $client);

        self::assertNotNull($reopened);
        self::assertSame('Изменённый результат сеанса', $reopened->result);
        self::assertSame('ВНУТРЕННЯЯ_ЗАМЕТКА_НЕ_ДЛЯ_PDF', $reopened->therapistNotes);
        self::assertSame(121, $reopened->measurements['before']['vitals']['sys']);
        self::assertSame(119, $reopened->measurements['after']['vitals']['sys']);
        self::assertSame(40, $reopened->measurements['before']['vitals']['pd']);
    }

    public function test_stage1c_photo_and_medical_file_stay_private_and_cross_tenant_session_is_denied(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $session = $this->makeSession($admin, $client, $specialist);
        $photo = app(UploadSessionPhoto::class)->handle(
            $admin,
            $session,
            $client,
            UploadedFile::fake()->image('front-before.jpg'),
            MedicalSessionAttachmentGallery::Before,
        );
        $medicalFile = app(UploadMedicalAttachment::class)->handle($admin, new AttachmentUploadCommand(
            file: $this->fakePdf('medical-summary.pdf'),
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $client->getKey(),
        ));
        app(LinkSessionAttachment::class)->handle($admin, $session, $client, (int) $medicalFile->getKey());

        Storage::disk('private')->assertExists($photo->attachment()->firstOrFail()->storage_path);
        Storage::disk('private')->assertExists($medicalFile->storage_path);
        $signedUrl = app(GetTemporaryAttachmentUrl::class)->handle($admin, $medicalFile, 15);

        [$otherOrganization, $otherAdmin] = $this->fixtureWithoutClient();
        app(OrganizationContext::class)->set($otherOrganization);

        $this->actingAs($otherAdmin)->get($signedUrl)->assertNotFound();
        $this->expectException(AuthorizationException::class);
        app(GetSession::class)->handle($otherAdmin, $session, $client);
    }

    public function test_audio_delivery_is_idempotent_and_retryable_attempt_can_be_retried(): void
    {
        [$organization, $admin, $client] = $this->fixtureWithoutSpecialist();
        ClientChannelIdentity::factory()->forClient($client)->create([
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'external_id' => 'stage1c-synthetic-recipient',
        ]);
        $audio = app(UploadMedicalAttachment::class)->handle($admin, new AttachmentUploadCommand(
            file: UploadedFile::fake()->createWithContent('session-audio.wav', $this->wavContent()),
            attachmentType: AttachmentType::AudioRecording,
            clientId: (int) $client->getKey(),
        ));
        $channel = new Stage1CRecordingNotificationChannel(
            NotificationDeliveryResult::retryable('telegram_rate_limited'),
            NotificationDeliveryResult::delivered('telegram-stage1c-1'),
        );
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));

        $first = app(SendMedicalAudioToClient::class)->handle($admin, $audio);
        self::assertSame('retryable', $first->status);
        self::assertSame(1, $first->attempt_count);

        $second = app(SendMedicalAudioToClient::class)->handle($admin, $audio->fresh());
        self::assertSame('delivered', $second->status);
        self::assertSame(2, $second->attempt_count);
        self::assertCount(2, $channel->messages);
        self::assertSame($second->idempotency_key, $channel->messages[1]->idempotencyKey);

        $terminalAudio = app(UploadMedicalAttachment::class)->handle($admin, new AttachmentUploadCommand(
            file: UploadedFile::fake()->createWithContent('terminal-audio.wav', $this->wavContent()),
            attachmentType: AttachmentType::AudioRecording,
            clientId: (int) $client->getKey(),
        ));
        $terminalChannel = new Stage1CRecordingNotificationChannel(NotificationDeliveryResult::unknown('delivery_outcome_unknown'));
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$terminalChannel]));

        $unknown = app(SendMedicalAudioToClient::class)->handle($admin, $terminalAudio);
        $duplicate = app(SendMedicalAudioToClient::class)->handle($admin, $terminalAudio->fresh());
        self::assertSame('unknown', $unknown->status);
        self::assertSame($unknown->id, $duplicate->id);
        self::assertSame(1, $duplicate->attempt_count);
        self::assertCount(1, $terminalChannel->messages);
        self::assertSame($organization->getKey(), $duplicate->organization_id);
    }

    public function test_pdf_contains_session_metrics_but_excludes_internal_notes_and_attachments(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->makeSession($admin, $client, $specialist, CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
        app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-11-17 13:00:00', 'UTC'),
            result: 'РЕЗУЛЬТАТ_В_АНКЕТЕ',
            therapistNotes: 'ВНУТРЕННЯЯ_ЗАМЕТКА_НЕ_В_АНКЕТЕ',
            measurements: [
                'before' => ['vitals' => ['sys' => 120, 'dia' => 80, 'pulse' => 72, 'pi' => 2.1, 'spo2' => 98]],
                'after_enabled' => true,
                'after' => ['vitals' => ['sys' => 118, 'dia' => 78, 'pulse' => 70, 'pi' => 2.5, 'spo2' => 99]],
            ],
        ));
        $photo = app(UploadMedicalAttachment::class)->handle($admin, new AttachmentUploadCommand(
            file: UploadedFile::fake()->image('private-photo.jpg'),
            attachmentType: AttachmentType::PosturePhoto,
            clientId: (int) $client->getKey(),
        ));
        $file = app(UploadMedicalAttachment::class)->handle($admin, new AttachmentUploadCommand(
            file: $this->fakePdf('private-report.pdf'),
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $client->getKey(),
        ));
        self::assertNotSame($photo->storage_path, $file->storage_path);

        $pdf = app(GenerateClientMedicalPdf::class)->handle($admin, $client);
        self::assertStringStartsWith('%PDF', $pdf);
        $document = (new PdfParser)->parseString($pdf);
        $text = collect(range(1, $document->getNumberOfPages()))
            ->map(fn (int $page): string => $document->getPage($page)?->getText() ?? '')
            ->implode("\n");

        self::assertStringContainsString('РЕЗУЛЬТАТ_В_АНКЕТЕ', $text);
        self::assertStringContainsString('PD', $text);
        self::assertStringContainsString('MAP', $text);
        self::assertStringContainsString('120', $text);
        self::assertStringNotContainsString('ВНУТРЕННЯЯ_ЗАМЕТКА_НЕ_В_АНКЕТЕ', $text);
        self::assertStringNotContainsString('private-photo.jpg', $text);
        self::assertStringNotContainsString('private-report.pdf', $text);
        self::assertSame($organization->getKey(), $client->organization_id);
    }

    /** @return array{0: Organization, 1: User, 2: Client, 3: Specialist} */
    private function fixture(): array
    {
        [$organization, $admin, $client] = $this->fixtureWithoutSpecialist();
        $specialist = Specialist::factory()->forOrganization($organization)->create();

        return [$organization, $admin, $client, $specialist];
    }

    /** @return array{0: Organization, 1: User} */
    private function fixtureWithoutClient(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    /** @return array{0: Organization, 1: User, 2: Client} */
    private function fixtureWithoutSpecialist(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client];
    }

    private function makeSession(
        User $admin,
        Client $client,
        Specialist $specialist,
        ?CarbonImmutable $occurredAt = null,
    ): MedicalSession {
        return MedicalSession::findOrFail(app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: $occurredAt ?? CarbonImmutable::parse('2026-11-17 09:00:00', 'UTC'),
        ))->id);
    }

    private function fakePdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n%%EOF");
    }

    private function wavContent(): string
    {
        $data = str_repeat("\0", 1600);

        return 'RIFF'.pack('V', 36 + strlen($data))
            .'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            .'data'.pack('V', strlen($data)).$data;
    }
}

final class Stage1CRecordingNotificationChannel implements NotificationChannel
{
    /** @var list<NotificationMessage> */
    public array $messages = [];

    /** @var list<NotificationDeliveryResult> */
    private array $results;

    public function __construct(NotificationDeliveryResult ...$results)
    {
        $this->results = $results;
    }

    public function name(): string
    {
        return 'telegram';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(supportsFileAttachments: true, supportsProactiveDelivery: true);
    }

    public function send(NotificationMessage $message): NotificationDeliveryResult
    {
        $this->messages[] = $message;
        $index = count($this->messages) - 1;

        return $this->results[$index] ?? $this->results[array_key_last($this->results)];
    }
}
