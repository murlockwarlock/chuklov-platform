<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\MedicalProfiles\Application\MedicalProfileCompletion;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Infrastructure\Logging\RedactSensitiveLogData;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

final class September26MedicalProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_medical_form_saves_reopens_and_projects_all_clinical_answers(): void
    {
        [$client, $staff] = $this->fixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $data = $this->answers('');
        unset($data['expected_snapshot']);
        $data['answers']['had_operations'] = true;
        $data['answers']['operation_year'] = 2020;
        $data['operations'] = 'Synthetic operation details';
        $data['answers']['had_injuries'] = true;
        $data['answers']['injury_year'] = 2021;
        $data['injuries'] = 'Synthetic injury details';
        $data['answers']['pain_vas'] = 10;
        $data['pain_not_measured'] = false;

        Livewire::actingAs($staff)->test(ViewClient::class, ['record' => $client->getKey()])
            ->callAction('editMedicalProfile', $data)->assertHasNoActionErrors();

        $profile = MedicalProfile::query()->where('client_id', $client->getKey())->sole();
        self::assertNotNull($profile->completed_at);
        self::assertNull($profile->client_confirmed_at);
        self::assertStringNotContainsString('Synthetic chronic', $profile->getRawOriginal('chronic_conditions'));
        self::assertStringNotContainsString('pain_vas', $profile->getRawOriginal('clinical_answers'));
        app(GetMedicalProfile::class)->invalidate();

        Livewire::actingAs($staff)->test(ViewClient::class, ['record' => $client->getKey()])
            ->assertSee('Synthetic chronic')->assertSee('Боль в шее')->assertSee('10 / 10')
            ->mountAction('editMedicalProfile')
            ->assertSchemaStateSet(['answers.pain_vas' => 10, 'answers.operation_year' => 2020, 'answers.injury_year' => 2021], 'mountedActionSchema0');

        $this->get(route('portal.medical-profile'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('profile.answers.pain_vas', 10)->where('profile.answers.operation_year', 2020)
            ->where('profile.answers.injury_year', 2021)->where('profile.fields.chronic_conditions', 'Synthetic chronic'));

        foreach ([0, null] as $value) {
            $data['answers']['pain_vas'] = $value;
            $data['pain_not_measured'] = $value === null;
            Livewire::actingAs($staff)->test(ViewClient::class, ['record' => $client->getKey()])
                ->callAction('editMedicalProfile', $data)->assertHasNoActionErrors();
            $this->get(route('portal.medical-profile'))->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profile.answers.pain_vas', $value)->where('profile.answers.operation_year', 2020));
        }
    }

    public function test_crm_medical_form_shows_server_validation_and_allows_correction_for_required_years(): void
    {
        [$client, $staff] = $this->fixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $data = $this->answers('');
        $data['answers']['had_operations'] = true;
        $data['answers']['had_injuries'] = true;
        $data['operations'] = 'Synthetic operation details';
        $data['injuries'] = 'Synthetic injury details';

        $component = Livewire::actingAs($staff)->test(ViewClient::class, ['record' => $client->getKey()])
            ->assertActionVisible('editMedicalProfile');
        $component->callAction('editMedicalProfile', $data)
            ->assertHasFormErrors(['answers.operation_year', 'answers.injury_year']);
        self::assertContains('Укажите год операции.', $component->instance()->getErrorBag()->all());
        self::assertContains('Укажите год травмы.', $component->instance()->getErrorBag()->all());

        self::assertFalse(MedicalProfile::query()->where('client_id', $client->getKey())->exists());
        self::assertFalse(AuditEvent::query()->where('target_type', MedicalProfile::class)->exists());

        $data['answers']['operation_year'] = 2020;
        $data['answers']['injury_year'] = 2021;

        Livewire::actingAs($staff)->test(ViewClient::class, ['record' => $client->getKey()])
            ->callAction('editMedicalProfile', $data)
            ->assertHasNoActionErrors()
            ->assertNotified('Медицинский профиль сохранён');

        $profile = app(GetMedicalProfile::class)->handle($staff, $client);
        $answers = json_decode((string) $profile?->clinicalAnswers, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2020, $answers['operation_year']);
        self::assertSame(2021, $answers['injury_year']);
        self::assertSame(1, AuditEvent::query()->where('target_type', MedicalProfile::class)->count());
    }

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

    public function test_removing_required_data_in_crm_invalidates_completion_until_client_reconfirms(): void
    {
        [$client, $staff] = $this->fixture();
        $snapshot = $this->get(route('portal.medical-profile'))->viewData('page')['props']['profile']['expectedSnapshot'];
        $this->post(route('portal.medical-profile.update'), $this->answers($snapshot))->assertSessionHasNoErrors();
        $completion = app(MedicalProfileCompletion::class);
        self::assertTrue($completion->isComplete($client->organization_id, $client->id));

        app(UpdateMedicalProfile::class)->handle($staff, $client, UpdateMedicalProfileCommand::fromArray(['goals' => null]));

        self::assertFalse($completion->isComplete($client->organization_id, $client->id));
        self::assertNull(MedicalProfile::query()->where('client_id', $client->id)->sole()->client_confirmed_at);
        $page = $this->get(route('portal.medical-profile'))->assertOk();
        $snapshot = $page->viewData('page')['props']['profile']['expectedSnapshot'];
        $this->post(route('portal.medical-profile.update'), $this->answers($snapshot))->assertSessionHasNoErrors();
        self::assertTrue($completion->isComplete($client->organization_id, $client->id));
        self::assertFalse($completion->isComplete(Organization::factory()->create()->id, $client->id));
    }

    public function test_complete_manual_crm_entry_stops_prompts_without_fabricating_client_confirmation(): void
    {
        [$client, $staff] = $this->fixture();
        $data = $this->answers('');
        app(UpdateMedicalProfile::class)->handle($staff, $client, new UpdateMedicalProfileCommand(
            complaints: $data['complaints'], goals: $data['goals'], chronicConditions: $data['chronic_conditions'],
            clinicalAnswers: json_encode($data['answers'], JSON_THROW_ON_ERROR), clinicalAnswersProvided: true,
            confirmCurrentData: true,
            anamnesisProvided: false, complaintsGoalsProvided: false, operationsInjuriesProvided: false,
            complaintsProvided: true, goalsProvided: true, chronicConditionsProvided: true,
        ));
        $profile = MedicalProfile::query()->where('client_id', $client->id)->sole();
        self::assertNotNull($profile->completed_at);
        self::assertNull($profile->client_confirmed_at);
        self::assertTrue(app(MedicalProfileCompletion::class)->isComplete($client->organization_id, $client->id));
        app(UpdateMedicalProfile::class)->handle($staff, $client, UpdateMedicalProfileCommand::fromArray(['goals' => null]));
        self::assertNull($profile->fresh()->completed_at);
        self::assertFalse(app(MedicalProfileCompletion::class)->isComplete($client->organization_id, $client->id));
    }

    public function test_direct_self_commands_cannot_confirm_empty_complaints_or_missing_operation_details(): void
    {
        [$client] = $this->fixture();
        $snapshot = $this->get(route('portal.medical-profile'))->viewData('page')['props']['profile']['expectedSnapshot'];
        $data = $this->answers($snapshot);
        foreach ([
            ['symptoms' => [], 'had_operations' => false, 'operation_year' => null, 'field' => 'complaints'],
            ['symptoms' => ['neck'], 'had_operations' => true, 'operation_year' => 2020, 'field' => 'operations'],
        ] as $invalid) {
            $answers = [...$data['answers'], 'symptoms' => $invalid['symptoms'], 'had_operations' => $invalid['had_operations'], 'operation_year' => $invalid['operation_year']];
            try {
                app(UpdateMedicalProfile::class)->handle($client, $client, new UpdateMedicalProfileCommand(
                    expectedSnapshot: $snapshot,
                    complaints: null,
                    goals: 'Synthetic goals',
                    operations: null,
                    clinicalAnswers: json_encode($answers, JSON_THROW_ON_ERROR),
                    anamnesisProvided: false,
                    complaintsGoalsProvided: false,
                    operationsInjuriesProvided: false,
                    complaintsProvided: true,
                    goalsProvided: true,
                    operationsProvided: true,
                    clinicalAnswersProvided: true,
                    confirmCurrentData: true,
                ));
                self::fail('Incomplete medical data must not be confirmed.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey($invalid['field'], $exception->errors());
            }
            self::assertFalse(MedicalProfile::query()->where('client_id', $client->getKey())->exists());
            self::assertSame(0, AuditEvent::query()->where('target_type', MedicalProfile::class)->count());
        }
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
            'pain_not_measured' => true,
        ]));
        self::assertSame('[REDACTED]', $record->context['chronic_conditions']);
        self::assertSame('[REDACTED]', $record->context['clinical_answers']);
        self::assertSame('[REDACTED]', $record->context['pain_not_measured']);
        self::assertSame('[REDACTED]', $record->context['goals']);
        self::assertSame('[REDACTED]', $record->context['answers']['symptoms']);
        self::assertSame('[REDACTED]', $record->context['answers']['pain_vas']);
        self::assertStringNotContainsString('Synthetic sensitive', $record->message);
    }

    public function test_disabled_client_records_rejects_a_direct_self_update(): void
    {
        [$client] = $this->fixture();
        $snapshot = $this->get(route('portal.medical-profile'))->viewData('page')['props']['profile']['expectedSnapshot'];
        $client->organization->featureFlags()->where('feature_key', OrganizationFeature::ClientRecords->value)->update(['enabled' => false]);
        OrganizationFeatureGate::invalidate();
        $this->post(route('portal.medical-profile.update'), $this->answers($snapshot))->assertForbidden();
        self::assertFalse(MedicalProfile::query()->where('client_id', $client->id)->exists());
    }

    public function test_legacy_combined_medical_fields_remain_readable_without_guessing_how_to_split_them(): void
    {
        [$client, $staff] = $this->fixture();
        app(UpdateMedicalProfile::class)->handle($staff, $client, UpdateMedicalProfileCommand::fromArray([
            'complaints_goals' => 'Synthetic legacy complaints and goals',
            'operations_injuries' => 'Synthetic legacy operations and injuries',
        ]));
        $this->get(route('portal.medical-profile'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('profile.legacyFields.complaintsGoals', 'Synthetic legacy complaints and goals')
            ->where('profile.legacyFields.operationsInjuries', 'Synthetic legacy operations and injuries')
            ->where('profile.fields.complaints', null)->where('profile.fields.operations', null));
        $snapshot = $this->get(route('portal.medical-profile'))->viewData('page')['props']['profile']['expectedSnapshot'];
        $this->post(route('portal.medical-profile.update'), $this->answers($snapshot))->assertSessionHasNoErrors();
        self::assertSame('Synthetic legacy complaints and goals', app(GetMedicalProfile::class)->handle($staff, $client)->complaintsGoals);
        self::assertStringNotContainsString('Synthetic legacy complaints', MedicalProfile::query()->where('client_id', $client->id)->sole()->getRawOriginal('complaints_goals'));
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['tenancy.default_organization_id' => $organization->id]);
        $organization->featureFlags()->create(['feature_key' => OrganizationFeature::ClientRecords->value, 'enabled' => true]);
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
