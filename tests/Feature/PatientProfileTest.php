<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ClientPortal\Application\PortalClientMessages;
use App\Modules\Identity\Application\AcceptPatientInvitation;
use App\Modules\Identity\Application\BindPatientInvitationToAccount;
use App\Modules\Identity\Application\CreatePatientProfile;
use App\Modules\Identity\Application\EnsureSelfPatientProfile;
use App\Modules\Identity\Application\GrantPatientAccess;
use App\Modules\Identity\Application\IssuePatientInvitation;
use App\Modules\Identity\Application\ResolvePatientClinicalClient;
use App\Modules\Identity\Application\RevokePatientAccess;
use App\Modules\Identity\Application\RevokePatientRelationship;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Identity\Domain\Models\PatientAccessGrant;
use App\Modules\Identity\Domain\Models\PatientInvitation;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\MedicalProfileReminderPresentation;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\PatientNotificationRecipientPolicy;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Database\Seeders\ScenarioNotificationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

final class PatientProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_identity_creation_is_isolated_idempotent_and_not_medical_authority(): void
    {
        $owner = $this->account();
        $self = app(EnsureSelfPatientProfile::class)->handle($owner);
        self::assertSame($owner->getKey(), $self->clinical_client_id);
        $patient = $this->patient($owner);
        self::assertNotSame($owner->getKey(), $patient->clinical_client_id);
        self::assertSame($patient->getKey(), $this->patient($owner)->getKey());
        self::assertSame(2, Client::query()->count());
        self::assertSame(1, AuditEvent::query()->where('action', 'patient.created')->count());
        self::assertNull($patient->linked_account_client_id);
        self::assertNull($patient->clinicalClient->email);
        self::assertNull($patient->clinicalClient->phone);
        $this->expectException(AuthorizationException::class);
        app(ResolvePatientClinicalClient::class)->handle($owner, (int) $patient->getKey());
    }

    public function test_invitation_binds_accepts_replays_and_revokes_without_transferring_or_deleting_history(): void
    {
        $owner = $this->account();
        $patient = $this->patient($owner);
        $adult = Client::factory()->forOrganization($owner->organization)->create(array_diff_key($this->details(), ['relationship' => true]));
        $invitation = app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000002');
        $token = $invitation->encrypted_token;
        self::assertNotSame($token, $invitation->getRawOriginal('encrypted_token'));
        self::assertSame($invitation->getKey(), app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000002')->getKey());
        app(BindPatientInvitationToAccount::class)->handle($token, $adult);
        app(BindPatientInvitationToAccount::class)->handle($token, $adult);
        app(AcceptPatientInvitation::class)->handle($adult, (int) $invitation->getKey(), true);
        app(AcceptPatientInvitation::class)->handle($adult, (int) $invitation->getKey(), true);
        self::assertSame(1, AuditEvent::query()->where('action', 'patient.invitation.accepted')->count());
        self::assertSame($patient->clinical_client_id, app(ResolvePatientClinicalClient::class)->handle($adult, (int) $patient->getKey())->getKey());
        app(RevokePatientRelationship::class)->handle($owner, $patient->refresh());
        app(RevokePatientRelationship::class)->handle($owner, $patient->refresh());
        self::assertNotNull($patient->refresh()->owner_revoked_at);
        self::assertNull($patient->linked_account_client_id);
        self::assertNotNull($invitation->refresh()->revoked_at);
        self::assertSame(1, AuditEvent::query()->where('action', 'patient.relationship.revoked')->count());
        try {
            app(ResolvePatientClinicalClient::class)->handle($adult, (int) $patient->getKey());
            self::fail('A revoked adult retained access to the patient identity.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
        self::assertSame(1, PatientProfile::query()->count());
        $this->expectException(AuthorizationException::class);
        app(AcceptPatientInvitation::class)->handle($adult, (int) $invitation->getKey(), true);
    }

    public function test_family_profile_http_validation_is_localized_and_success_is_confirmed(): void
    {
        $owner = $this->account();
        $this->withSession(['client_portal.client_id' => $owner->getKey()]);
        $url = route('portal.family.store');
        $key = '00000000-0000-4000-8000-000000000005';

        $this->post($url, ['creation_key' => $key])
            ->assertSessionHasErrors([
                'first_name' => app(PortalClientMessages::class)->message('family_name_required'),
                'last_name' => app(PortalClientMessages::class)->message('family_name_required'),
                'gender' => app(PortalClientMessages::class)->message('family_gender_required'),
                'birth_date' => app(PortalClientMessages::class)->message('family_birth_date_required'),
                'relationship' => app(PortalClientMessages::class)->message('family_relationship_required'),
            ]);
        self::assertSame(0, PatientProfile::query()->where('owner_client_id', $owner->getKey())->count());

        $this->post($url, [...$this->details(), 'creation_key' => $key, 'birth_date' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors([
                'birth_date' => app(PortalClientMessages::class)->message('family_birth_date_future'),
            ]);

        $this->post($url, [...$this->details(), 'creation_key' => $key])
            ->assertRedirect(route('portal.family'))
            ->assertSessionHas('success', app(PortalClientMessages::class)->message('family_created'));
        self::assertSame(1, PatientProfile::query()->where('owner_client_id', $owner->getKey())->count());
        self::assertSame(1, AuditEvent::query()->where('action', 'patient.created')->count());
    }

    public function test_missing_telegram_configuration_does_not_create_or_audit_an_invitation_and_retry_is_safe(): void
    {
        $owner = $this->account();
        $patient = $this->patient($owner);
        $this->withSession(['client_portal.client_id' => $owner->getKey()]);
        $requestKey = '00000000-0000-4000-8000-000000000006';
        config()->set('portal.telegram.bot_username', null);
        Log::spy();

        $failureResponse = $this->postJson(route('portal.family.invite', ['patientId' => $patient->getKey()]), ['request_key' => $requestKey]);
        $failureResponse
            ->assertStatus(503)
            ->assertJsonPath('message', app(PortalClientMessages::class)->message('family_telegram_unavailable'))
            ->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame(0, PatientInvitation::query()->count());
        self::assertSame(0, AuditEvent::query()->where('action', 'patient.invitation.created')->count());

        Log::shouldHaveReceived('warning')->once()->with('portal.family.invitation.failed', Mockery::on(
            static fn (array $context): bool => ($context['organization_id'] ?? null) === $owner->organization_id
                && ($context['actor_client_id'] ?? null) === $owner->getKey()
                && ($context['entity_id'] ?? null) === $patient->getKey()
                && ($context['provider'] ?? null) === 'telegram'
                && ($context['safe_error_code'] ?? null) === 'telegram_bot_configuration_missing'
                && ($context['outcome'] ?? null) === 'failed'
                && isset($context['occurred_at_utc'])
                && ! array_key_exists('token', $context)
                && ! array_key_exists('message', $context),
        ));

        config()->set('portal.telegram.bot_username', 'FamilyQaBot');
        $this->postJson(route('portal.family.invite', ['patientId' => $patient->getKey()]), ['request_key' => $requestKey])
            ->assertOk()
            ->assertJsonPath('url', fn (string $url): bool => str_starts_with($url, 'https://t.me/FamilyQaBot?start=family_'));
        self::assertSame(1, PatientInvitation::query()->count());
        self::assertSame(1, AuditEvent::query()->where('action', 'patient.invitation.created')->count());
    }

    public function test_invitation_acceptance_reports_confirmation_error_then_persists_acceptance_and_audit(): void
    {
        $owner = $this->account();
        $patient = $this->patient($owner);
        $adult = Client::factory()->forOrganization($owner->organization)->create(array_diff_key($this->details(), ['relationship' => true]));
        $invitation = app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000007');
        app(BindPatientInvitationToAccount::class)->handle($invitation->encrypted_token, $adult);
        $this->withSession([
            'client_portal.client_id' => $adult->getKey(),
            'patient_invitation_id' => $invitation->getKey(),
        ]);
        Log::spy();

        $this->post(route('portal.family.invitation.accept'), ['confirm_identity' => false])
            ->assertSessionHasErrors([
                'confirm_identity' => __('Подтвердите, что указанные данные относятся к вам.'),
            ]);
        self::assertNull($invitation->refresh()->accepted_at);
        self::assertNull($patient->refresh()->linked_account_client_id);
        self::assertSame(0, AuditEvent::query()->where('action', 'patient.invitation.accepted')->count());
        Log::shouldHaveReceived('warning')->once()->with('portal.family.invitation.failed', Mockery::on(
            static fn (array $context): bool => ($context['organization_id'] ?? null) === $adult->organization_id
                && ($context['actor_client_id'] ?? null) === $adult->getKey()
                && ($context['entity_id'] ?? null) === $invitation->getKey()
                && ($context['operation'] ?? null) === 'patient.invitation.accept'
                && ($context['safe_error_code'] ?? null) === 'identity_confirmation_missing'
                && ($context['outcome'] ?? null) === 'denied'
                && isset($context['occurred_at_utc'])
                && ! array_key_exists('medical_text', $context)
                && ! array_key_exists('token', $context),
        ));

        $this->post(route('portal.family.invitation.accept'), ['confirm_identity' => true])
            ->assertRedirect(route('portal.family.invitation'))
            ->assertSessionHas('success', app(PortalClientMessages::class)->message('family_invitation_accepted'));
        self::assertNotNull($invitation->refresh()->accepted_at);
        self::assertSame($adult->getKey(), $patient->refresh()->linked_account_client_id);
        self::assertSame(1, AuditEvent::query()->where('action', 'patient.invitation.accepted')->count());
        $this->get(route('portal.family.invitation'))->assertInertia(fn (AssertableInertia $page) => $page->where('accepted', true));
        $this->post(route('portal.family.invitation.accept'), ['confirm_identity' => true])->assertRedirect(route('portal.family.invitation'));
        self::assertSame(1, AuditEvent::query()->where('action', 'patient.invitation.accepted')->count());
    }

    public function test_revoked_and_expired_invitation_links_explain_recovery_and_log_safe_reasons(): void
    {
        $owner = $this->account();
        $patient = $this->patient($owner);
        $revokedRecipient = Client::factory()->forOrganization($owner->organization)->create(array_diff_key($this->details(), ['relationship' => true]));
        $revokedInvite = app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000008');
        app(BindPatientInvitationToAccount::class)->handle($revokedInvite->encrypted_token, $revokedRecipient);
        app(RevokePatientRelationship::class)->handle($owner, $patient);

        Log::spy();
        $this->withSession([
            'client_portal.client_id' => $revokedRecipient->getKey(),
            'patient_invitation_id' => $revokedInvite->getKey(),
        ])->get(route('portal.family.invitation'))
            ->assertRedirect(route('portal.family'))
            ->assertSessionHas('error', app(PortalClientMessages::class)->message('family_invitation_revoked'));

        $expiredRecipient = Client::factory()->forOrganization($owner->organization)->create(array_diff_key($this->details(), ['relationship' => true]));
        $expiredInvite = app(IssuePatientInvitation::class)->handle($owner, $patient->refresh(), '00000000-0000-4000-8000-000000000009');
        app(BindPatientInvitationToAccount::class)->handle($expiredInvite->encrypted_token, $expiredRecipient);
        $expiredInvite->forceFill(['expires_at' => now()->subMinute()])->save();

        $wrongRecipient = Client::factory()->forOrganization($owner->organization)->create();
        $this->withSession([
            'client_portal.client_id' => $wrongRecipient->getKey(),
            'patient_invitation_id' => $expiredInvite->getKey(),
        ])->get(route('portal.family.invitation'))
            ->assertRedirect(route('portal.family'))
            ->assertSessionHas('error', app(PortalClientMessages::class)->message('family_invitation_wrong_account'));

        $this->withSession([
            'client_portal.client_id' => $expiredRecipient->getKey(),
            'patient_invitation_id' => $expiredInvite->getKey(),
        ])->get(route('portal.family.invitation'))
            ->assertRedirect(route('portal.family'))
            ->assertSessionHas('error', app(PortalClientMessages::class)->message('family_invitation_expired'));

        Log::shouldHaveReceived('warning')->times(3)->with('portal.family.invitation.failed', Mockery::on(
            static fn (array $context): bool => in_array($context['safe_error_code'] ?? null, ['invitation_revoked', 'invitation_expired', 'invitation_wrong_account'], true)
                && ($context['operation'] ?? null) === 'patient.invitation.open'
                && ($context['outcome'] ?? null) === 'denied'
                && isset($context['organization_id'], $context['actor_client_id'], $context['entity_id'], $context['occurred_at_utc'])
                && ! array_key_exists('name', $context)
                && ! array_key_exists('token', $context)
                && ! array_key_exists('medical_text', $context),
        ));
    }

    public function test_revoked_adult_can_be_reinvited_without_losing_medical_history(): void
    {
        $owner = $this->account();
        $patient = $this->patient($owner);
        $adult = Client::factory()->forOrganization($owner->organization)->create(array_diff_key($this->details(), ['relationship' => true]));
        $invitation = app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000002');
        app(BindPatientInvitationToAccount::class)->handle($invitation->encrypted_token, $adult);
        app(AcceptPatientInvitation::class)->handle($adult, (int) $invitation->getKey(), true);
        $clinicalClientId = $patient->clinical_client_id;

        app(RevokePatientRelationship::class)->handle($owner, $patient->refresh());
        $replacement = app(IssuePatientInvitation::class)->handle($owner, $patient->refresh(), '00000000-0000-4000-8000-000000000003');
        app(BindPatientInvitationToAccount::class)->handle($replacement->encrypted_token, $adult);
        app(AcceptPatientInvitation::class)->handle($adult, (int) $replacement->getKey(), true);

        self::assertSame($clinicalClientId, $patient->refresh()->clinical_client_id);
        self::assertSame($adult->getKey(), $patient->linked_account_client_id);
        self::assertNull($patient->owner_revoked_at);
        self::assertSame($clinicalClientId, app(ResolvePatientClinicalClient::class)->handle($adult, (int) $patient->getKey())->getKey());
        self::assertSame(2, PatientInvitation::query()->count());
    }

    public function test_minor_profile_cannot_issue_adult_account_invitation(): void
    {
        $owner = $this->account();
        $patient = $this->patient($owner, ['birth_date' => now()->subYears(7)->toDateString(), 'relationship' => 'CHILD']);
        $this->expectException(ValidationException::class);
        app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000002');
    }

    public function test_delegated_medical_access_requires_published_policy_is_idempotent_and_revokes_cleanly(): void
    {
        $owner = $this->account();
        $patient = $this->patient($owner);
        $delegate = Client::factory()->forOrganization($owner->organization)->create([
            'birth_date' => '1988-03-04',
            'first_name' => 'Delegate',
            'last_name' => 'Account',
        ]);
        $policy = LegalDocument::factory()->forOrganization($owner->organization)->create([
            'document_type' => 'delegated_medical_access',
            'purpose' => 'delegated_medical_access_policy',
            'is_required' => false,
            'status' => 'published',
            'published_at' => now(),
            'effective_at' => now(),
            'version' => 'qa-2026-10-27',
        ]);

        $grant = app(GrantPatientAccess::class)->handle(
            actor: $owner,
            patient: $patient,
            grantee: $delegate,
            legalDocumentId: (int) $policy->getKey(),
            requestKey: '00000000-0000-4000-8000-000000000021',
        );
        $replayed = app(GrantPatientAccess::class)->handle(
            actor: $owner,
            patient: $patient->refresh(),
            grantee: $delegate,
            legalDocumentId: (int) $policy->getKey(),
            requestKey: '00000000-0000-4000-8000-000000000021',
        );
        self::assertSame($grant->getKey(), $replayed->getKey());
        self::assertSame($patient->clinical_client_id, app(ResolvePatientClinicalClient::class)->handle($delegate, (int) $patient->getKey())->getKey());
        self::assertSame(1, PatientAccessGrant::query()->count());
        self::assertSame(1, AuditEvent::query()->where('action', 'patient.access.granted')->count());

        app(RevokePatientAccess::class)->handle($owner, $grant->refresh());
        app(RevokePatientAccess::class)->handle($owner, $grant->refresh());
        $this->expectException(AuthorizationException::class);
        app(ResolvePatientClinicalClient::class)->handle($delegate, (int) $patient->getKey());
    }

    public function test_delegated_medical_access_without_published_policy_is_fail_closed(): void
    {
        $owner = $this->account();
        $patient = $this->patient($owner);
        $delegate = Client::factory()->forOrganization($owner->organization)->create(['birth_date' => '1988-03-04']);

        $this->expectException(ValidationException::class);
        app(GrantPatientAccess::class)->handle(
            actor: $owner,
            patient: $patient,
            grantee: $delegate,
            legalDocumentId: 999999,
            requestKey: '00000000-0000-4000-8000-000000000022',
        );
    }

    public function test_foreign_account_cannot_manage_profile_or_consume_bound_invitation(): void
    {
        $owner = $this->account();
        $patient = $this->patient($owner);
        $other = Client::factory()->forOrganization($owner->organization)->create(array_diff_key($this->details(), ['relationship' => true]));
        $wrong = Client::factory()->forOrganization($owner->organization)->create();
        $invitation = app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000002');
        app(BindPatientInvitationToAccount::class)->handle($invitation->encrypted_token, $other);
        foreach ([$wrong, Client::factory()->forOrganization(Organization::factory()->create())->create()] as $actor) {
            try {
                app(RevokePatientRelationship::class)->handle($actor, $patient);
                self::fail('A foreign account revoked a family relationship.');
            } catch (AuthorizationException) {
                self::assertNull($patient->refresh()->owner_revoked_at);
            }
            try {
                app(BindPatientInvitationToAccount::class)->handle($invitation->encrypted_token, $actor);
                self::fail('An invitation was transferred to another account.');
            } catch (AuthorizationException) {
                self::assertSame($other->getKey(), $invitation->refresh()->intended_client_id);
            }
        }
        self::assertSame(1, PatientInvitation::query()->count());
    }

    public function test_medical_context_uses_booking_patient_and_never_owner_chart_or_family_delegation(): void
    {
        $owner = $this->account();
        $this->withSession(['client_portal.client_id' => $owner->getKey()]);
        $this->saveMedicalProfile('Owner synthetic history');
        $ownerProfile = MedicalProfile::query()->where('client_id', $owner->getKey())->sole();
        $encrypted = $ownerProfile->getRawOriginal('complaints');
        $patient = $this->patient($owner);
        $booking = Booking::factory()->forClient($owner)
            ->forSpecialist(Specialist::factory()->forOrganization($owner->organization)->create())
            ->forService(Service::factory()->forOrganization($owner->organization)->create(['requires_medical_profile' => true]))
            ->create(['patient_profile_id' => $patient->getKey()]);
        $this->withSession(['client_portal.client_id' => $owner->getKey()]);
        $this->get(route('portal.medical-profile', ['booking_id' => $booking->getKey()]))->assertForbidden();
        $this->get(route('portal.medical-profile', ['patient_profile_id' => $patient->getKey()]))->assertForbidden();
        $this->get(route('portal.medical-profile'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('profile.fields.complaints', 'Owner synthetic history'));
        $adult = Client::factory()->forOrganization($owner->organization)->create(array_diff_key($this->details(), ['relationship' => true]));
        $invitation = app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000002');
        app(BindPatientInvitationToAccount::class)->handle($invitation->encrypted_token, $adult);
        app(AcceptPatientInvitation::class)->handle($adult, (int) $invitation->getKey(), true);
        $this->withSession(['client_portal.client_id' => $adult->getKey()]);
        $this->saveMedicalProfile('Patient synthetic history', (int) $booking->getKey());
        $subjectProfile = MedicalProfile::query()->where('client_id', $patient->clinical_client_id)->sole();
        self::assertSame($patient->getKey(), $subjectProfile->patient_profile_id);
        self::assertStringNotContainsString('Patient synthetic history', $subjectProfile->getRawOriginal('complaints'));
        self::assertSame($encrypted, $ownerProfile->refresh()->getRawOriginal('complaints'));
        $this->withSession(['client_portal.client_id' => $adult->getKey()]);
        $this->get(route('portal.medical-profile', ['booking_id' => $booking->getKey()]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('patientId', $patient->getKey())->where('profile.fields.complaints', 'Patient synthetic history'));
        $this->get(route('portal.bookings.show', ['bookingId' => $booking->getKey()]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('booking.patientName', $patient->clinicalClient->full_name)->where('booking.canCancel', false)->where('booking.canReschedule', false));
        $self = app(EnsureSelfPatientProfile::class)->handle($adult);
        $this->get(route('portal.medical-profile', ['booking_id' => $booking->getKey(), 'patient_profile_id' => $self->getKey()]))->assertForbidden();
        app(RevokePatientRelationship::class)->handle($owner, $patient->refresh());
        $this->get(route('portal.medical-profile', ['patient_profile_id' => $patient->getKey()]))->assertForbidden();
        $this->withSession(['client_portal.client_id' => $owner->getKey()]);
        $this->get(route('portal.medical-profile', ['patient_profile_id' => $patient->getKey()]))->assertForbidden();
    }

    public function test_family_medical_reminder_targets_the_patient_not_the_payer(): void
    {
        $owner = $this->account();
        $patient = $this->patient($owner);
        $booking = Booking::factory()->forClient($owner)
            ->forSpecialist(Specialist::factory()->forOrganization($owner->organization)->create())
            ->forService(Service::factory()->forOrganization($owner->organization)->create(['requires_medical_profile' => true]))
            ->create(['patient_profile_id' => $patient->getKey()]);
        $action = new ScenarioAction;
        $action->forceFill(['recipient_type' => 'client', 'organization_id' => $owner->organization_id, 'client_id' => $owner->getKey()]);
        self::assertFalse(app(MedicalProfileReminderPresentation::class)->needsPrompt($action, $booking));
        $adult = Client::factory()->forOrganization($owner->organization)->create(array_diff_key($this->details(), ['relationship' => true]));
        $invitation = app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000002');
        app(BindPatientInvitationToAccount::class)->handle($invitation->encrypted_token, $adult);
        app(AcceptPatientInvitation::class)->handle($adult, (int) $invitation->getKey(), true);
        $booking->unsetRelation('patientProfile');
        $action->client_id = $adult->getKey();
        self::assertTrue(app(MedicalProfileReminderPresentation::class)->needsPrompt($action, $booking));
        $profile = new MedicalProfile;
        $profile->forceFill(['organization_id' => $owner->organization_id, 'client_id' => $patient->clinical_client_id,
            'patient_profile_id' => $patient->getKey(), 'encryption_key_version' => 1, 'client_confirmed_at' => now()])->save();
        self::assertFalse(app(MedicalProfileReminderPresentation::class)->needsPrompt($action, $booking));
    }

    public function test_accepting_invitation_materializes_upcoming_booking_notice_and_reminders_once_for_linked_account(): void
    {
        $owner = $this->account();
        $patient = $this->patient($owner);
        $specialist = Specialist::factory()->forOrganization($owner->organization)->create();
        $service = Service::factory()->forOrganization($owner->organization)->create(['requires_medical_profile' => true]);
        $booking = Booking::factory()->forClient($owner)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'patient_profile_id' => $patient->getKey(),
                'status' => BookingStatus::Requested,
                'starts_at' => CarbonImmutable::now()->addDays(4),
                'ends_at' => CarbonImmutable::now()->addDays(4)->addHour(),
                'blocking_ends_at' => CarbonImmutable::now()->addDays(4)->addHour(),
            ]);
        app(ScenarioNotificationSeeder::class)->run();
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'family-link-notification', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($event->getKey());

        $adult = Client::factory()->forOrganization($owner->organization)->create(array_diff_key($this->details(), ['relationship' => true]));
        $invitation = app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000004');
        app(BindPatientInvitationToAccount::class)->handle($invitation->encrypted_token, $adult);
        app(AcceptPatientInvitation::class)->handle($adult, (int) $invitation->getKey(), true);

        self::assertSame(1, ScenarioAction::query()->where('scenario_event_id', $event->getKey())
            ->where('client_id', $adult->getKey())->where('kind', 'scenario')->count());
        self::assertGreaterThan(0, ScenarioAction::query()->where('booking_id', $booking->getKey())
            ->where('client_id', $adult->getKey())->where('kind', 'appointment_reminder')->count());
        $scenarioActions = ScenarioAction::query()->where('scenario_event_id', $event->getKey())
            ->where('client_id', $adult->getKey())->count();
        $reminderActions = ScenarioAction::query()->where('booking_id', $booking->getKey())
            ->where('client_id', $adult->getKey())->where('kind', 'appointment_reminder')->count();

        app(AcceptPatientInvitation::class)->handle($adult, (int) $invitation->getKey(), true);

        self::assertSame($scenarioActions, ScenarioAction::query()->where('scenario_event_id', $event->getKey())
            ->where('client_id', $adult->getKey())->count());
        self::assertSame($reminderActions, ScenarioAction::query()->where('booking_id', $booking->getKey())
            ->where('client_id', $adult->getKey())->where('kind', 'appointment_reminder')->count());
    }

    public function test_linked_patient_materialization_respects_booking_notification_policy(): void
    {
        $owner = $this->account();
        $admin = User::factory()->forOrganization($owner->organization)->create();
        $patient = $this->patient($owner);
        $specialist = Specialist::factory()->forOrganization($owner->organization)->create();
        $service = Service::factory()->forOrganization($owner->organization)->create(['requires_medical_profile' => false]);
        $booking = Booking::factory()->forClient($owner)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'patient_profile_id' => $patient->getKey(),
                'status' => BookingStatus::Requested,
                'starts_at' => CarbonImmutable::now()->addDays(4),
                'ends_at' => CarbonImmutable::now()->addDays(4)->addHour(),
                'blocking_ends_at' => CarbonImmutable::now()->addDays(4)->addHour(),
            ]);
        app(ScenarioNotificationSeeder::class)->run();
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'family-policy-booking', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::PatientNotificationRecipients,
            PatientNotificationRecipientPolicy::BookerOnly->value,
        );

        $adult = Client::factory()->forOrganization($owner->organization)->create(array_diff_key($this->details(), ['relationship' => true]));
        $invitation = app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000010');
        app(BindPatientInvitationToAccount::class)->handle($invitation->encrypted_token, $adult);
        app(AcceptPatientInvitation::class)->handle($adult, (int) $invitation->getKey(), true);

        self::assertSame(0, ScenarioAction::query()->where('scenario_event_id', $event->getKey())
            ->where('client_id', $adult->getKey())->count());
        self::assertSame(0, ScenarioAction::query()->where('booking_id', $booking->getKey())
            ->where('client_id', $adult->getKey())->where('kind', 'appointment_reminder')->count());
    }

    public function test_existing_client_adoption_preserves_ciphertext_timestamps_and_primary_name(): void
    {
        $owner = $this->account();
        $this->withSession(['client_portal.client_id' => $owner->getKey()]);
        $this->saveMedicalProfile('Original synthetic medical history');
        $profile = MedicalProfile::query()->where('client_id', $owner->getKey())->sole();
        $patientId = $profile->patient_profile_id;
        DB::table('medical_profiles')->where('id', $profile->getKey())->update(['patient_profile_id' => null]);
        DB::table('patient_profiles')->where('id', $patientId)->delete();
        $before = $profile->fresh()->getRawOriginal();
        $clientBefore = $owner->fresh()->getRawOriginal();
        $migration = require database_path('migrations/2026_10_14_163142_adopt_existing_client_patient_identities.php');
        $migration->up();
        $migration->up();
        $after = $profile->fresh()->getRawOriginal();
        self::assertNotNull($after['patient_profile_id']);
        unset($before['patient_profile_id'], $after['patient_profile_id']);
        self::assertSame($before, $after);
        self::assertSame($clientBefore, $owner->fresh()->getRawOriginal());
        self::assertSame(1, PatientProfile::query()->where('clinical_client_id', $owner->getKey())->count());
    }

    public function test_owner_corrects_unlinked_identity_but_cannot_rewrite_another_adults_identity(): void
    {
        $owner = $this->account();
        $patient = $this->patient($owner);
        $invitation = app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000002');
        $this->withSession(['client_portal.client_id' => $owner->getKey()]);
        $this->post(route('portal.family.update', ['patientId' => $patient->getKey()]), [...$this->details(), 'first_name' => 'Corrected'])
            ->assertSessionHasNoErrors()->assertRedirect(route('portal.family'));
        self::assertSame('Corrected', $patient->refresh()->first_name);
        self::assertSame('Corrected', $patient->clinicalClient->first_name);
        self::assertNotNull($invitation->refresh()->revoked_at);
        $this->post(route('portal.family.update', ['patientId' => $patient->getKey()]), [...$this->details(), 'first_name' => 'Corrected'])->assertSessionHasNoErrors();
        self::assertSame(1, AuditEvent::query()->where('action', 'patient.updated')->count());
        $adult = Client::factory()->forOrganization($owner->organization)->create([...array_diff_key($this->details(), ['relationship' => true]), 'first_name' => 'Corrected']);
        $new = app(IssuePatientInvitation::class)->handle($owner, $patient, '00000000-0000-4000-8000-000000000003');
        app(BindPatientInvitationToAccount::class)->handle($new->encrypted_token, $adult);
        app(AcceptPatientInvitation::class)->handle($adult, (int) $new->getKey(), true);
        $this->post(route('portal.family.update', ['patientId' => $patient->getKey()]), [...$this->details(), 'first_name' => 'Someone Else'])->assertForbidden();
        self::assertSame('Corrected', $patient->refresh()->first_name);
    }

    private function saveMedicalProfile(string $complaints, ?int $bookingId = null): void
    {
        $query = $bookingId === null ? [] : ['booking_id' => $bookingId];
        $snapshot = $this->get(route('portal.medical-profile', $query))->assertOk()->viewData('page')['props']['profile']['expectedSnapshot'];
        $this->post(route('portal.medical-profile.update', $query), [
            'expected_snapshot' => $snapshot, 'complaints' => $complaints, 'goals' => 'Synthetic goals',
            'answers' => ['symptoms' => [], 'pain_vas' => null, 'no_chronic_conditions' => true,
                'had_operations' => false, 'had_injuries' => false],
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    private function account(): Client
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create(['feature_key' => OrganizationFeature::ClientRecords->value, 'enabled' => true]);

        return Client::factory()->forOrganization($organization)->create();
    }

    /** @return array<string, string> */
    private function details(): array
    {
        return ['first_name' => 'Test', 'last_name' => 'Patient', 'gender' => 'FEMALE', 'birth_date' => '1990-01-02', 'relationship' => 'SPOUSE'];
    }

    /** @param array<string, string> $overrides */
    private function patient(Client $owner, array $overrides = []): PatientProfile
    {
        return app(CreatePatientProfile::class)->handle($owner, [...$this->details(), ...$overrides], '00000000-0000-4000-8000-000000000001');
    }
}
