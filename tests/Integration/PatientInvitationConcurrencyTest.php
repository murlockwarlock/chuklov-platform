<?php

namespace Tests\Integration;

use App\Modules\Identity\Application\BindPatientInvitationToAccount;
use App\Modules\Identity\Application\CreatePatientProfile;
use App\Modules\Identity\Application\IssuePatientInvitation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientInvitation;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PatientInvitationConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }
        parent::tearDown();
    }

    public function test_concurrent_profile_creation_replays_one_patient_and_one_clinical_identity(): void
    {
        $owner = $this->owner();
        $ownerId = (int) $owner->getKey();
        $result = Concurrency::driver('process')->run([
            static fn (): int => self::createInProcess($ownerId),
            static fn (): int => self::createInProcess($ownerId),
        ]);
        self::assertSame($result[0], $result[1]);
        self::assertSame(1, PatientProfile::query()->count());
        self::assertSame(2, Client::query()->count());
        self::assertSame(1, AuditEvent::query()->where('action', 'patient.created')->count());
    }

    public function test_two_accounts_cannot_consume_the_same_invitation(): void
    {
        $owner = $this->owner();
        $patientId = self::createInProcess((int) $owner->getKey());
        $patient = PatientProfile::query()->findOrFail($patientId);
        $invitation = app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000002');
        $token = $invitation->encrypted_token;
        $first = (int) Client::factory()->forOrganization($owner->organization)->create()->getKey();
        $second = (int) Client::factory()->forOrganization($owner->organization)->create()->getKey();
        $result = Concurrency::driver('process')->run([
            static fn (): string => self::bindInProcess($first, $token),
            static fn (): string => self::bindInProcess($second, $token),
        ]);
        sort($result);
        self::assertSame(['bound', 'denied'], $result);
        self::assertContains((int) $invitation->refresh()->intended_client_id, [$first, $second]);
        self::assertNull($patient->refresh()->linked_account_client_id);
        self::assertSame(1, PatientInvitation::query()->count());
    }

    private function owner(): Client
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Patient identity races require an isolated PostgreSQL database.');
        }
        $organization = Organization::factory()->create();
        $organization->featureFlags()->create(['feature_key' => OrganizationFeature::ClientRecords->value, 'enabled' => true]);
        app(OrganizationContext::class)->set($organization);

        return Client::factory()->forOrganization($organization)->create();
    }

    private static function createInProcess(int $ownerId): int
    {
        $owner = Client::query()->findOrFail($ownerId);
        app(OrganizationContext::class)->set($owner->organization);

        return (int) app(CreatePatientProfile::class)->handle($owner, [
            'first_name' => 'Synthetic', 'last_name' => 'Patient', 'gender' => 'FEMALE',
            'birth_date' => '1990-01-02', 'relationship' => 'SPOUSE',
        ], '00000000-0000-4000-8000-000000000001')->getKey();
    }

    private static function bindInProcess(int $accountId, string $token): string
    {
        $account = Client::query()->findOrFail($accountId);
        app(OrganizationContext::class)->set($account->organization);
        try {
            app(BindPatientInvitationToAccount::class)->handle($token, $account);

            return 'bound';
        } catch (AuthorizationException) {
            return 'denied';
        }
    }
}
