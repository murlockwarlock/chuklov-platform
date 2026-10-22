<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\User;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Identity\Application\ResetStagingClientAccount;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\PatientInvitation;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Referrals\Domain\Models\ClientReferralIdentity;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class ResetStagingClientAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_operator_can_reset_a_staging_client_from_the_client_card(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        ClientChannelIdentity::factory()->forClient($client)->create();
        ClientOnboarding::factory()->forClient($client)->create();
        ClientReferralIdentity::forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'public_code' => 'reset-test-code',
        ]);
        DB::table('client_acquisition_registrations')->insert([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'session_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->assertActionVisible('resetStagingAccount')
            ->callAction('resetStagingAccount')
            ->assertNotified('Аккаунт сброшен для повторного теста')
            ->assertRedirect(ClientResource::getUrl('index'));

        self::assertDatabaseMissing('clients', ['id' => $client->getKey()]);
        self::assertDatabaseMissing('client_channel_identities', ['client_id' => $client->getKey()]);
        self::assertDatabaseMissing('client_onboardings', ['client_id' => $client->getKey()]);
        self::assertDatabaseMissing('client_referral_identities', ['client_id' => $client->getKey()]);
        self::assertDatabaseMissing('client_acquisition_registrations', ['client_id' => $client->getKey()]);
        self::assertDatabaseHas('audit_events', [
            'organization_id' => $organization->getKey(),
            'actor_user_id' => $admin->getKey(),
            'action' => 'client.staging_account.reset',
            'target_id' => (string) $client->getKey(),
        ]);
    }

    public function test_reset_removes_a_client_with_working_history(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        $selfPatient = PatientProfile::forceCreate([
            'organization_id' => $organization->getKey(),
            'owner_client_id' => $client->getKey(),
            'clinical_client_id' => $client->getKey(),
            'linked_account_client_id' => $client->getKey(),
            'relationship' => 'SELF',
        ]);
        $booking->forceFill(['patient_profile_id' => $selfPatient->getKey()])->save();
        $familyClient = Client::factory()->forOrganization($organization)->create();
        $familyPatient = PatientProfile::forceCreate([
            'organization_id' => $organization->getKey(),
            'owner_client_id' => $client->getKey(),
            'clinical_client_id' => $familyClient->getKey(),
            'linked_account_client_id' => null,
            'first_name' => 'Synthetic',
            'last_name' => 'Patient',
            'relationship' => 'CHILD',
            'gender' => 'FEMALE',
            'birth_date' => '2016-01-01',
        ]);
        $invitation = PatientInvitation::forceCreate([
            'organization_id' => $organization->getKey(),
            'owner_client_id' => $client->getKey(),
            'patient_profile_id' => $familyPatient->getKey(),
            'request_key' => (string) Str::uuid(),
            'token_hash' => hash('sha256', 'reset-staging-patient-invitation'),
            'encrypted_token' => 'synthetic-invitation-token',
            'expires_at' => now()->addDay(),
        ]);
        $bookingEvent = BookingEvent::factory()->forBooking($booking)->create();
        ClientAttribution::forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'source_type' => 'manual',
            'source' => 'CRM',
            'capture_channel' => 'crm',
            'captured_at' => now(),
            'accepted_at' => now(),
        ]);

        app(ResetStagingClientAccount::class)->handle($admin, $client);

        self::assertDatabaseMissing('clients', ['id' => $client->getKey()]);
        self::assertDatabaseMissing('bookings', ['id' => $booking->getKey()]);
        self::assertDatabaseMissing('booking_events', ['id' => $bookingEvent->getKey()]);
        self::assertDatabaseMissing('client_attributions', ['client_id' => $client->getKey()]);
        self::assertDatabaseMissing('patient_invitations', ['id' => $invitation->getKey()]);
        self::assertDatabaseMissing('patient_profiles', ['id' => $selfPatient->getKey()]);
        self::assertDatabaseMissing('patient_profiles', ['id' => $familyPatient->getKey()]);
        self::assertDatabaseHas('clients', ['id' => $familyClient->getKey()]);
        self::assertDatabaseHas('audit_events', [
            'organization_id' => $organization->getKey(),
            'actor_user_id' => $admin->getKey(),
            'action' => 'patient.invitation.removed',
            'target_id' => (string) $invitation->getKey(),
        ]);
        self::assertDatabaseHas('audit_events', [
            'organization_id' => $organization->getKey(),
            'actor_user_id' => $admin->getKey(),
            'action' => 'patient.profile.deleted',
            'target_id' => (string) $selfPatient->getKey(),
        ]);
    }

    public function test_reset_rejects_a_client_linked_to_another_owners_patient_profile(): void
    {
        [$organization, $admin, $owner] = $this->fixture();
        $clinicalClient = Client::factory()->forOrganization($organization)->create();
        $patient = PatientProfile::forceCreate([
            'organization_id' => $organization->getKey(),
            'owner_client_id' => $owner->getKey(),
            'clinical_client_id' => $clinicalClient->getKey(),
            'linked_account_client_id' => $clinicalClient->getKey(),
            'first_name' => 'Synthetic',
            'last_name' => 'Patient',
            'relationship' => 'CHILD',
            'gender' => 'FEMALE',
            'birth_date' => '2016-01-01',
        ]);

        try {
            app(ResetStagingClientAccount::class)->handle($admin, $clinicalClient);
            self::fail('Resetting a linked account must not delete another owner’s patient profile.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'Сначала отзовите связь с профилем пациента другого владельца.',
                $exception->errors()['account'][0],
            );
        }

        self::assertDatabaseHas('clients', ['id' => $clinicalClient->getKey()]);
        self::assertDatabaseHas('patient_profiles', [
            'id' => $patient->getKey(),
            'owner_client_id' => $owner->getKey(),
            'linked_account_client_id' => $clinicalClient->getKey(),
        ]);
        self::assertDatabaseMissing('audit_events', [
            'action' => 'client.staging_account.reset',
            'target_id' => (string) $clinicalClient->getKey(),
        ]);
    }

    public function test_reset_rejects_a_client_with_purchase_history_and_preserves_it(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $purchaseId = $this->createPurchase($organization, $client);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->callAction('resetStagingAccount')
            ->assertNotified(Notification::make()
                ->title('Аккаунт не сброшен')
                ->body('Сброс невозможен: у клиента есть история покупок или подарочных сертификатов, которую нужно сохранить. Используйте новый тестовый аккаунт.')
                ->danger());

        self::assertDatabaseHas('clients', ['id' => $client->getKey()]);
        self::assertDatabaseHas('commerce_purchases', ['id' => $purchaseId, 'client_id' => $client->getKey()]);
        self::assertDatabaseMissing('audit_events', [
            'action' => 'client.staging_account.reset',
            'target_id' => (string) $client->getKey(),
        ]);
    }

    public function test_reset_rejects_a_client_holding_another_clients_gift_certificate(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $purchaser = Client::factory()->forOrganization($organization)->create();
        $purchaseId = $this->createPurchase($organization, $purchaser);
        $purchaseItemId = DB::table('commerce_purchase_items')->insertGetId([
            'organization_id' => $organization->getKey(),
            'purchase_id' => $purchaseId,
            'sellable_type' => Service::class,
            'sellable_id' => 999,
            'quantity' => 1,
            'amount_minor' => 1000,
            'currency' => 'USD',
            'product_snapshot' => json_encode(['name' => 'Synthetic gift certificate'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fulfillmentId = DB::table('commerce_purchase_fulfillments')->insertGetId([
            'organization_id' => $organization->getKey(),
            'purchase_item_id' => $purchaseItemId,
            'provider_type' => 'gift_certificate',
            'status' => 'fulfilled',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certificateId = DB::table('gift_certificates')->insertGetId([
            'organization_id' => $organization->getKey(),
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'purchase_fulfillment_id' => $fulfillmentId,
            'purchaser_client_id' => $purchaser->getKey(),
            'current_holder_client_id' => $client->getKey(),
            'original_amount_minor' => 1000,
            'currency' => 'USD',
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->callAction('resetStagingAccount')
            ->assertNotified(Notification::make()
                ->title('Аккаунт не сброшен')
                ->body('Сброс невозможен: у клиента есть история покупок или подарочных сертификатов, которую нужно сохранить. Используйте новый тестовый аккаунт.')
                ->danger());

        self::assertDatabaseHas('clients', ['id' => $client->getKey()]);
        self::assertDatabaseHas('clients', ['id' => $purchaser->getKey()]);
        self::assertDatabaseHas('gift_certificates', [
            'id' => $certificateId,
            'purchaser_client_id' => $purchaser->getKey(),
            'current_holder_client_id' => $client->getKey(),
        ]);
        self::assertDatabaseMissing('audit_events', [
            'action' => 'client.staging_account.reset',
            'target_id' => (string) $client->getKey(),
        ]);
    }

    public function test_unexpected_reset_failure_shows_and_logs_the_same_safe_reference(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        DB::table('medical_attachments')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'uploaded_by_user_id' => $admin->getKey(),
            'attachment_type' => 'medical_report',
            'disk' => 'private',
            'storage_path' => 'staging/medical-attachments/synthetic.pdf',
            'original_filename' => 'synthetic.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256_checksum' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->mock(AttachmentStorageInterface::class)
            ->shouldReceive('delete')
            ->once()
            ->with('staging/medical-attachments/synthetic.pdf')
            ->andThrow(new \RuntimeException('SQL details and private data'));
        Log::spy();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->callAction('resetStagingAccount');
        $context = null;

        Log::shouldHaveReceived('error')
            ->once()
            ->with('client.staging_account.reset_failed', \Mockery::on(function (array $data) use (&$context, $organization, $admin, $client): bool {
                $context = $data;

                return $data['event_name'] === 'client.staging_account.reset_failed'
                    && (int) $data['organization_id'] === (int) $organization->getKey()
                    && (int) $data['actor_user_id'] === (int) $admin->getKey()
                    && $data['entity_type'] === 'client'
                    && (int) $data['entity_id'] === (int) $client->getKey()
                    && $data['safe_error_code'] === 'storage_cleanup_failed'
                    && $data['exception_class'] === \RuntimeException::class
                    && $data['outcome'] === 'partial'
                    && preg_match('/^[0-9a-f-]{36}$/', $data['reference_id']) === 1
                    && isset($data['occurred_at_utc'])
                    && ! array_key_exists('message', $data);
            }));

        self::assertNotNull($context);
        $component->assertNotified(Notification::make()
            ->title('Аккаунт удалён не полностью')
            ->body('Аккаунт удалён, но не удалось полностью очистить связанные файлы. Передайте администратору код обращения: '.$context['reference_id'])
            ->danger());
        $component->assertRedirect(ClientResource::getUrl('index'));
        self::assertDatabaseMissing('clients', ['id' => $client->getKey()]);
        self::assertDatabaseHas('audit_events', [
            'action' => 'client.staging_account.reset',
            'target_id' => (string) $client->getKey(),
        ]);
    }

    public function test_reset_rejects_a_client_from_another_organization(): void
    {
        [$organization, $admin] = $this->fixture();
        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();

        try {
            app(ResetStagingClientAccount::class)->handle($admin, $foreignClient);
            self::fail('The reset should be blocked for a foreign client.');
        } catch (AuthorizationException) {
            self::assertDatabaseHas('clients', [
                'organization_id' => $foreignOrganization->getKey(),
                'id' => $foreignClient->getKey(),
            ]);
        }

        self::assertDatabaseHas('organizations', ['id' => $organization->getKey()]);
    }

    public function test_reset_is_rejected_outside_safe_environments(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->app->detectEnvironment(static fn (): string => 'production');

        try {
            app(ResetStagingClientAccount::class)->handle($admin, $client);
            self::fail('The reset should be unavailable in production.');
        } catch (AuthorizationException) {
            self::assertDatabaseHas('clients', [
                'organization_id' => $organization->getKey(),
                'id' => $client->getKey(),
            ]);
        }
    }

    /** @return array{Organization, User, Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client];
    }

    private function createPurchase(Organization $organization, Client $client): int
    {
        return (int) DB::table('commerce_purchases')->insertGetId([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'status' => 'paid',
            'total_amount_minor' => 1000,
            'currency' => 'USD',
            'purchase_snapshot' => json_encode(['kind' => 'synthetic'], JSON_THROW_ON_ERROR),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
