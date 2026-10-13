<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Infrastructure\Logging\RedactSensitiveLogData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

final class September26MedicalProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_medical_update_is_encrypted_visible_in_crm_and_preserved_after_staff_correction(): void
    {
        [$client, $staff] = $this->fixture();
        $page = $this->get(route('portal.medical-profile'))->assertOk();
        $snapshot = $page->viewData('page')['props']['profile']['expectedSnapshot'];
        $data = $this->answers($snapshot);
        $this->post(route('portal.medical-profile.update'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $profile = MedicalProfile::query()->where('client_id', $client->id)->firstOrFail();
        self::assertNotNull($profile->client_confirmed_at);
        self::assertStringNotContainsString('Synthetic chronic', $profile->getRawOriginal('chronic_conditions'));
        self::assertStringNotContainsString('pain_vas', $profile->getRawOriginal('clinical_answers'));
        $crm = app(GetMedicalProfile::class)->handle($staff, $client);
        self::assertSame('Synthetic chronic', $crm->chronicConditions);
        self::assertSame(0, json_decode($crm->clinicalAnswers, true, flags: JSON_THROW_ON_ERROR)['pain_vas']);
        app(UpdateMedicalProfile::class)->handle($staff, $client, UpdateMedicalProfileCommand::fromArray(['chronic_conditions' => 'Synthetic corrected']));
        $this->get(route('portal.medical-profile'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('profile.fields.chronic_conditions', 'Synthetic corrected')
            ->where('profile.answers.pain_vas', 0)
            ->missing('root_cause_hypothesis')->missing('session_protocol')->missing('ai_summary_json'));
        $audit = AuditEvent::query()->where('target_type', MedicalProfile::class)->get()->toJson();
        self::assertStringNotContainsString('Synthetic chronic', $audit);
        self::assertStringNotContainsString('Synthetic corrected', $audit);
    }

    public function test_invalid_and_stale_forms_do_not_replace_saved_medical_data(): void
    {
        [$client] = $this->fixture();
        $snapshot = $this->get(route('portal.medical-profile'))->viewData('page')['props']['profile']['expectedSnapshot'];
        $data = $this->answers($snapshot);
        $data['answers']['pain_vas'] = 11;
        $this->post(route('portal.medical-profile.update'), $data)->assertSessionHasErrors('answers.pain_vas');
        self::assertFalse(MedicalProfile::query()->where('client_id', $client->id)->exists());
        $data['answers']['pain_vas'] = 10;
        $this->post(route('portal.medical-profile.update'), $data)->assertSessionHasNoErrors();
        $data['complaints'] = 'Stale overwrite';
        $this->post(route('portal.medical-profile.update'), $data)->assertSessionHasErrors('medical_profile');
        $this->get(route('portal.medical-profile'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('profile.fields.complaints', 'Synthetic symptoms')->where('profile.answers.pain_vas', 10));
    }

    public function test_foreign_booking_return_and_unauthenticated_profile_are_rejected(): void
    {
        $this->fixture();
        $this->withSession(['client_portal.client_id' => null]);
        $this->get(route('portal.medical-profile'))->assertUnauthorized();
        $this->fixture();
        $this->get(route('portal.medical-profile', ['booking_id' => 999999]))->assertNotFound();
    }

    public function test_extended_clinical_values_are_redacted_from_log_context_and_messages(): void
    {
        $processor = new RedactSensitiveLogData;
        $record = $processor(new LogRecord(new \DateTimeImmutable, 'test', Level::Info, 'chronic_conditions: Synthetic sensitive message', [
            'chronic_conditions' => 'Synthetic chronic', 'clinical_answers' => 'Synthetic answer',
            'answers' => ['symptoms' => ['neck'], 'pain_vas' => 10], 'goals' => 'Synthetic goal',
        ]));
        self::assertSame('[REDACTED]', $record->context['chronic_conditions']);
        self::assertSame('[REDACTED]', $record->context['clinical_answers']);
        self::assertSame('[REDACTED]', $record->context['goals']);
        self::assertSame('[REDACTED]', $record->context['answers']['symptoms']);
        self::assertSame('[REDACTED]', $record->context['answers']['pain_vas']);
        self::assertStringNotContainsString('Synthetic sensitive', $record->message);
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['tenancy.default_organization_id' => $organization->id]);
        $client = Client::factory()->forOrganization($organization)->create();
        $staff = User::factory()->forOrganization($organization)->create();
        $this->withSession(['client_portal.client_id' => $client->id]);

        return [$client, $staff];
    }

    private function answers(string $snapshot): array
    {
        return [
            'expected_snapshot' => $snapshot, 'complaints' => 'Synthetic symptoms', 'goals' => 'Synthetic goals',
            'chronic_conditions' => 'Synthetic chronic', 'operations' => '', 'injuries' => '', 'medicines' => '', 'supplements' => '',
            'answers' => ['symptoms' => ['neck'], 'pain_vas' => 0, 'no_chronic_conditions' => false,
                'had_operations' => false, 'operation_year' => null, 'had_injuries' => false, 'injury_year' => null],
        ];
    }
}
