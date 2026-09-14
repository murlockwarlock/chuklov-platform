<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\ScenarioActions\Pages\ListScenarioActions;
use App\Filament\Resources\Specialists\SpecialistResource;
use App\Filament\Resources\SurveyAttempts\Pages\ListSurveyAttempts;
use App\Filament\Resources\SurveyAttempts\Pages\ViewSurveyAttempt;
use App\Filament\Support\CrmEntityLinks;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Surveys\Application\CreateSurveyDefinition;
use App\Modules\Surveys\Application\PublishSurveyVersion;
use App\Modules\Surveys\Application\StartSurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CrmEntityClickThroughTest extends TestCase
{
    use RefreshDatabase;

    public function test_survey_attempt_list_links_to_the_canonical_client_view(): void
    {
        [$actor, $client, $attempt] = $this->surveyAttemptFixture();

        $html = Livewire::actingAs($actor)
            ->test(ListSurveyAttempts::class)
            ->html();

        self::assertStringContainsString(
            'href="'.ClientResource::getUrl('view', ['record' => $client->getKey()]).'"',
            $html,
        );
        self::assertStringContainsString((string) $attempt->getKey(), $html);
    }

    public function test_survey_attempt_view_links_to_the_canonical_client_view(): void
    {
        [$actor, $client, $attempt] = $this->surveyAttemptFixture();

        $html = Livewire::actingAs($actor)
            ->test(ViewSurveyAttempt::class, ['record' => $attempt->getKey()])
            ->html();

        self::assertStringContainsString(
            'href="'.ClientResource::getUrl('view', ['record' => $client->getKey()]).'"',
            $html,
        );
    }

    public function test_booking_view_links_to_canonical_client_and_specialist_views(): void
    {
        [$actor, $client, $specialist, $booking] = $this->bookingFixture();

        $html = Livewire::actingAs($actor)
            ->test(ViewBooking::class, ['record' => $booking->getKey()])
            ->assertSuccessful()
            ->html();

        self::assertStringContainsString(
            'href="'.ClientResource::getUrl('view', ['record' => $client->getKey()]).'"',
            $html,
        );
        self::assertStringContainsString(
            'href="'.SpecialistResource::getUrl('view', ['record' => $specialist->getKey()]).'"',
            $html,
        );
    }

    public function test_entity_links_fail_closed_for_a_foreign_organization(): void
    {
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $actor = User::factory()->forOrganization($organization)->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();
        $foreignSpecialist = Specialist::factory()->forOrganization($foreignOrganization)->create();
        $this->setFilamentContext($actor, $organization);

        self::assertNotNull(CrmEntityLinks::clientUrl($client));
        self::assertNotNull(CrmEntityLinks::specialistUrl($specialist));
        self::assertNull(CrmEntityLinks::clientUrl($foreignClient));
        self::assertNull(CrmEntityLinks::specialistUrl($foreignSpecialist));
    }

    public function test_client_link_is_plain_when_client_card_authorization_is_missing(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->forOrganization($organization)->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => false,
        ]);
        $client = Client::factory()->forOrganization($organization)->create();
        $this->setFilamentContext($actor, $organization);

        self::assertFalse(ClientResource::canViewAny());
        self::assertNull(CrmEntityLinks::clientUrl($client));
    }

    public function test_free_form_staff_names_are_not_auto_linked_to_specialist_cards(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->forOrganization($organization)->create();
        $recipient = User::factory()->forOrganization($organization)->create(['name' => 'Murlock Warlock']);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $template = NotificationTemplate::factory()->forOrganization($organization)->create();
        $version = NotificationTemplateVersion::factory()->forTemplate($template)->create();
        $rule = ScenarioRule::factory()->usingTemplate($version)->createdBy($actor)->create();
        $event = ScenarioEvent::factory()->forOrganization($organization)->create();
        ScenarioAction::factory()->forEvent($event)->forRule($rule)->forTemplate($version)->create([
            'recipient_type' => 'staff',
            'recipient_user_id' => $recipient->getKey(),
        ]);
        $this->setFilamentContext($actor, $organization);

        $html = Livewire::actingAs($actor)
            ->test(ListScenarioActions::class)
            ->assertSuccessful()
            ->html();

        self::assertStringContainsString('Murlock Warlock', $html);
        self::assertStringNotContainsString(
            'href="'.SpecialistResource::getUrl('view', ['record' => $recipient->getKey()]).'"',
            $html,
        );
    }

    /** @return array{0: User, 1: Client, 2: SurveyAttempt} */
    private function surveyAttemptFixture(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Murlock Warlock']);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => 'client_records',
            'enabled' => true,
        ]);
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $definition = app(CreateSurveyDefinition::class)->handle($actor, [
            'definition_key' => 'click-through-'.uniqid(),
            'title' => 'Click-through survey',
            'title_en' => null,
            'description' => null,
            'description_en' => null,
            'metric_schema_key' => null,
            'source_reference' => null,
            'definition' => [
                'sections' => [[
                    'key' => 'general',
                    'title' => 'General',
                    'questions' => [[
                        'key' => 'score',
                        'type' => 'integer',
                        'label' => 'Answer',
                        'required' => false,
                    ]],
                ]],
            ],
            'scoring' => [
                'metrics' => [['key' => 'score', 'label' => 'Score']],
                'rules' => [['question_key' => 'score', 'metric_key' => 'score', 'operator' => 'numeric_value']],
                'thresholds' => [],
            ],
        ]);
        app(PublishSurveyVersion::class)->handle($actor, $definition->versions()->sole());
        $attempt = app(StartSurveyAttempt::class)->handle($client, $definition);

        return [$actor, $client, $attempt];
    }

    /** @return array{0: User, 1: Client, 2: Specialist, 3: Booking} */
    private function bookingFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $actor = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Booking Client']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['display_name' => 'Booking Specialist']);
        $service = Service::factory()->forOrganization($organization)->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        $this->setFilamentContext($actor, $organization);

        return [$actor, $client, $specialist, $booking];
    }

    private function setFilamentContext(User $actor, Organization $organization): void
    {
        $this->actingAs($actor);
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
}
