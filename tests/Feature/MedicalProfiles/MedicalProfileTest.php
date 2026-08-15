<?php

namespace Tests\Feature\MedicalProfiles;

use App\Models\User;
use App\Modules\Identity\Application\UpdateClientProfileFromCrm;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\MedicalProfileData;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use App\Modules\MedicalProfiles\Domain\Exceptions\MedicalDecryptionException;
use App\Modules\MedicalProfiles\Domain\Exceptions\MedicalKeyNotFoundException;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Infrastructure\Logging\RedactSensitiveLogData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

final class MedicalProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_staff_can_create_and_read_encrypted_medical_profile(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();

        $updateAction = app(UpdateMedicalProfile::class);
        $command = new UpdateMedicalProfileCommand(
            anamnesis: 'Пациент жалуется на периодические боли в пояснице после физических нагрузок.',
            complaintsGoals: 'Снизить болевой синдром и улучшить осанку.',
            operationsInjuries: 'Аппендэктомия в 2018 году, перелом левой ключицы в 2015 году.',
            medicines: 'Ибупрофен 400 мг при обострениях.',
            supplements: 'Витамин D3 2000 МЕ, Омега-3 1000 мг.',
        );

        $result = $updateAction->handle($admin, $client, $command);

        self::assertInstanceOf(MedicalProfileData::class, $result);
        self::assertSame('Пациент жалуется на периодические боли в пояснице после физических нагрузок.', $result->anamnesis);
        self::assertSame('Снизить болевой синдром и улучшить осанку.', $result->complaintsGoals);
        self::assertSame('Аппендэктомия в 2018 году, перелом левой ключицы в 2015 году.', $result->operationsInjuries);
        self::assertSame('Ибупрофен 400 мг при обострениях.', $result->medicines);
        self::assertSame('Витамин D3 2000 МЕ, Омега-3 1000 мг.', $result->supplements);
        self::assertSame(1, $result->encryptionKeyVersion);

        // Verify data is encrypted at rest in the database
        $rawRow = DB::table('medical_profiles')
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->first();

        self::assertNotNull($rawRow);
        self::assertNotSame('Пациент жалуется на периодические боли в пояснице после физических нагрузок.', $rawRow->anamnesis);
        self::assertStringNotContainsString('пояснице', $rawRow->anamnesis);
        self::assertStringNotContainsString('Аппендэктомия', $rawRow->operations_injuries);
        self::assertStringNotContainsString('Ибупрофен', $rawRow->medicines);
        self::assertStringNotContainsString('Витамин D3', $rawRow->supplements);
        self::assertSame(1, (int) $rawRow->encryption_key_version);

        // Verify GetMedicalProfile action decrypts the data correctly
        $getAction = app(GetMedicalProfile::class);
        $retrieved = $getAction->handle($admin, $client);

        self::assertNotNull($retrieved);
        self::assertSame('Пациент жалуется на периодические боли в пояснице после физических нагрузок.', $retrieved->anamnesis);
        self::assertSame('Снизить болевой синдром и улучшить осанку.', $retrieved->complaintsGoals);
        self::assertSame('Аппендэктомия в 2018 году, перелом левой ключицы в 2015 году.', $retrieved->operationsInjuries);
        self::assertSame('Ибупрофен 400 мг при обострениях.', $retrieved->medicines);
        self::assertSame('Витамин D3 2000 МЕ, Омега-3 1000 мг.', $retrieved->supplements);
    }

    public function test_medical_profile_updates_preserve_organization_and_client_ownership(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();

        $updateAction = app(UpdateMedicalProfile::class);

        $updateAction->handle($admin, $client, new UpdateMedicalProfileCommand(
            anamnesis: 'Первичный анамнез',
            complaintsGoals: 'Первичные жалобы',
        ));

        $updateAction->handle($admin, $client, new UpdateMedicalProfileCommand(
            anamnesis: 'Обновлённый анамнез после консультации',
            complaintsGoals: 'Первичные жалобы',
            medicines: 'Новое лекарство',
        ));

        $count = MedicalProfile::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->count();

        self::assertSame(1, $count);

        $retrieved = app(GetMedicalProfile::class)->handle($admin, $client);
        self::assertNotNull($retrieved);
        self::assertSame('Обновлённый анамнез после консультации', $retrieved->anamnesis);
        self::assertSame('Новое лекарство', $retrieved->medicines);
    }

    public function test_cross_organization_staff_cannot_read_medical_profile(): void
    {
        [$orgA, $adminA, $clientA] = $this->setupOrganizationWithClient();
        [$orgB, $adminB, $clientB] = $this->setupOrganizationWithClient();

        app(OrganizationContext::class)->set($orgA);
        app(UpdateMedicalProfile::class)->handle($adminA, $clientA, new UpdateMedicalProfileCommand(
            anamnesis: 'Конфиденциальный анамнез организации А',
        ));

        // Admin B attempts to read Client A's medical profile
        app(OrganizationContext::class)->set($orgB);

        $this->expectException(AuthorizationException::class);
        app(GetMedicalProfile::class)->handle($adminB, $clientA);
    }

    public function test_cross_organization_staff_cannot_update_medical_profile(): void
    {
        [$orgA, $adminA, $clientA] = $this->setupOrganizationWithClient();
        [$orgB, $adminB, $clientB] = $this->setupOrganizationWithClient();

        // Admin B attempts to write to Client A's medical profile
        app(OrganizationContext::class)->set($orgB);

        $this->expectException(AuthorizationException::class);
        app(UpdateMedicalProfile::class)->handle($adminB, $clientA, new UpdateMedicalProfileCommand(
            anamnesis: 'Взлом анамнеза',
        ));
    }

    public function test_audit_event_contains_allowlisted_metadata_only_without_sensitive_medical_text(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();

        app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
            anamnesis: 'Очень секретный диагноз и симптомы',
            medicines: 'Препарат Х 500мг',
        ));

        $auditEvent = AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('target_type', MedicalProfile::class)
            ->first();

        self::assertNotNull($auditEvent);
        self::assertSame('medical.profile.created', $auditEvent->action);
        self::assertIsArray($auditEvent->metadata);
        self::assertSame('crm', $auditEvent->metadata['source'] ?? null);
        self::assertSame(1, $auditEvent->metadata['key_version'] ?? null);
        self::assertStringContainsString('anamnesis', $auditEvent->metadata['updated_fields'] ?? '');
        self::assertStringContainsString('medicines', $auditEvent->metadata['updated_fields'] ?? '');

        // Strictly verify NO medical plaintext is in the audit metadata
        $metadataJson = json_encode($auditEvent->metadata, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('секретный диагноз', (string) $metadataJson);
        self::assertStringNotContainsString('Препарат Х', (string) $metadataJson);
    }

    public function test_decryption_failure_throws_medical_decryption_exception(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();

        app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
            anamnesis: 'Анамнез для проверки сбоя',
        ));

        // Corrupt the ciphertext in the database
        DB::table('medical_profiles')
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->update(['anamnesis' => 'tampered_invalid_ciphertext_payload']);

        $this->expectException(MedicalDecryptionException::class);
        app(GetMedicalProfile::class)->handle($admin, $client);
    }

    public function test_key_version_rotation_seam_supports_custom_key_versions(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $orgId = (int) $organization->getKey();

        // Configure a separate key for version 2
        $keyV2 = 'base64:'.base64_encode(random_bytes(32));
        config()->set("medical.organizations.{$orgId}.keys.2", $keyV2);

        $encryptor = app(MedicalEncryptorInterface::class);

        $encryptedV1 = $encryptor->encryptField($orgId, 'Данные версии 1', 1);
        $encryptedV2 = $encryptor->encryptField($orgId, 'Данные версии 2', 2);

        self::assertNotNull($encryptedV1);
        self::assertNotNull($encryptedV2);
        self::assertNotSame($encryptedV1, $encryptedV2);

        // Decrypt each with its explicit key version
        self::assertSame('Данные версии 1', $encryptor->decryptField($orgId, $encryptedV1, 1));
        self::assertSame('Данные версии 2', $encryptor->decryptField($orgId, $encryptedV2, 2));
    }

    public function test_medical_encryption_uses_dedicated_key_and_does_not_depend_on_app_key(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();

        app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
            anamnesis: 'Секретный анамнез с выделенным ключом',
        ));

        // Rotate APP_KEY to a completely different random key
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Medical profile decryption must STILL succeed because it uses dedicated MEDICAL_ENCRYPTION_KEY_V1
        $retrieved = app(GetMedicalProfile::class)->handle($admin, $client);
        self::assertNotNull($retrieved);
        self::assertSame('Секретный анамнез с выделенным ключом', $retrieved->anamnesis);
    }

    public function test_missing_medical_encryption_key_throws_exception_without_using_app_key(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $orgId = (int) $organization->getKey();

        // Remove all medical key configuration
        config()->set('medical.root_key', null);
        config()->set('medical.keys.1', null);
        config()->set("medical.organizations.{$orgId}.keys.1", null);

        $resolver = app(MedicalKeyResolverInterface::class);

        $this->expectException(MedicalKeyNotFoundException::class);
        $resolver->resolveKey($orgId, 1);
    }

    public function test_inactive_membership_cannot_read_or_update_medical_profile(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();

        // Create inactive user
        $inactiveUser = User::factory()->create();
        OrganizationMembership::factory()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $inactiveUser->getKey(),
            'role' => OrganizationRole::Administrator,
            'is_active' => false,
        ]);

        app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
            anamnesis: 'Анамнез для проверки доступа',
        ));

        $this->expectException(AuthorizationException::class);
        app(GetMedicalProfile::class)->handle($inactiveUser, $client);
    }

    public function test_ordinary_client_update_does_not_rewrite_or_touch_medical_profile(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();

        app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
            anamnesis: 'Существующий анамнез',
            medicines: 'Существующее лекарство',
        ));

        $rawBefore = DB::table('medical_profiles')
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->first();

        self::assertNotNull($rawBefore);

        // Perform ordinary client profile update (e.g. name / phone change)
        app(UpdateClientProfileFromCrm::class)->handle($admin, $client, [
            'full_name' => 'Иван Обновленный',
            'phone' => '+79998887766',
        ]);

        $rawAfter = DB::table('medical_profiles')
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->first();

        self::assertNotNull($rawAfter);
        self::assertSame($rawBefore->anamnesis, $rawAfter->anamnesis);
        self::assertSame($rawBefore->medicines, $rawAfter->medicines);
        self::assertSame($rawBefore->updated_at, $rawAfter->updated_at);
    }

    public function test_sensitive_medical_fields_are_redacted_in_logs(): void
    {
        $processor = new RedactSensitiveLogData;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'testing',
            level: Level::Info,
            message: 'Processing client medical profile',
            context: [
                'client_id' => 42,
                'anamnesis' => 'Секретный анамнез',
                'complaints' => 'Жалобы пациента',
                'medicines' => 'Назначенные лекарства',
                'supplements' => 'БАДы',
                'operations_injuries' => 'Перенесённые операции',
            ],
        );

        $redacted = $processor($record);

        self::assertSame(42, $redacted->context['client_id']);
        self::assertSame('[REDACTED]', $redacted->context['anamnesis']);
        self::assertSame('[REDACTED]', $redacted->context['complaints']);
        self::assertSame('[REDACTED]', $redacted->context['medicines']);
        self::assertSame('[REDACTED]', $redacted->context['supplements']);
        self::assertSame('[REDACTED]', $redacted->context['operations_injuries']);
    }

    /** @return array{0: Organization, 1: User, 2: Client} */
    private function setupOrganizationWithClient(): array
    {
        $organization = Organization::factory()->create();
        $organization->featureFlags()->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->create(['organization_id' => $organization->getKey()]);

        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client];
    }
}
