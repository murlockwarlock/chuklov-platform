<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\ConditionEvaluatorRegistry;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioConditionSet;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BookingMedicalProfileConditionTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_is_rechecked_without_decrypting_clinical_data(): void
    {
        $context = $this->context();
        $this->mock(MedicalEncryptorInterface::class)->shouldNotReceive('decryptProfile', 'decryptField');
        self::assertTrue($this->conditionMatches($context));
        $profile = new MedicalProfile;
        $profile->forceFill([
            'organization_id' => $context->client->organization_id,
            'client_id' => $context->client->id,
            'encryption_key_version' => 1,
        ])->save();
        self::assertTrue($this->conditionMatches($context));
        $profile->forceFill(['client_confirmed_at' => now()])->save();
        self::assertFalse($this->conditionMatches($context));
        $profile->forceFill(['client_confirmed_at' => null])->save();
        self::assertTrue($this->conditionMatches($context));
    }

    public function test_nonmedical_terminal_and_past_bookings_do_not_require_a_prompt(): void
    {
        $context = $this->context();
        $service = Service::query()->findOrFail($context->booking->service_id);
        $service->forceFill(['requires_medical_profile' => false])->save();
        self::assertFalse($this->conditionMatches($context));
        $service->forceFill(['requires_medical_profile' => true])->save();
        foreach (BookingStatus::cases() as $status) {
            $context->booking->forceFill(['status' => $status]);
            self::assertSame(in_array($status, [BookingStatus::Requested, BookingStatus::PendingReview, BookingStatus::Confirmed], true), $this->conditionMatches($context));
        }
        $context->booking->forceFill(['status' => BookingStatus::Confirmed, 'starts_at' => now()]);
        self::assertFalse($this->conditionMatches($context));
        $context->booking->forceFill(['starts_at' => now()->subHour()]);
        self::assertFalse($this->conditionMatches($context));
    }

    public function test_foreign_event_client_service_and_missing_context_fail_closed(): void
    {
        $context = $this->context();
        $foreign = $this->context();
        self::assertFalse($this->conditionMatches(new ScenarioEvaluationContext($foreign->event, $context->booking, $context->client)));
        self::assertFalse($this->conditionMatches(new ScenarioEvaluationContext($context->event, $context->booking, $foreign->client)));
        $otherClient = Client::factory()->forOrganization($context->client->organization)->create();
        self::assertFalse($this->conditionMatches(new ScenarioEvaluationContext($context->event, $context->booking, $otherClient)));
        self::assertFalse($this->conditionMatches(new ScenarioEvaluationContext($context->event, null, $context->client)));
        self::assertFalse($this->conditionMatches(new ScenarioEvaluationContext($context->event, $context->booking, null)));
        $context->booking->forceFill(['service_id' => $foreign->booking->service_id]);
        self::assertFalse($this->conditionMatches($context));
    }

    public function test_disabling_client_records_stops_prompts_even_in_the_same_worker_process(): void
    {
        $context = $this->context();
        self::assertTrue($this->conditionMatches($context));
        $context->client->organization->featureFlags()->where('feature_key', OrganizationFeature::ClientRecords->value)->update(['enabled' => false]);
        self::assertFalse($this->conditionMatches($context));
    }

    private function conditionMatches(ScenarioEvaluationContext $context): bool
    {
        return app(ConditionEvaluatorRegistry::class)->matches(ScenarioConditionSet::from([
            ['type' => 'booking.needs_medical_profile', 'operator' => 'equals', 'value' => true],
        ]), $context);
    }

    private function context(): ScenarioEvaluationContext
    {
        $organization = Organization::factory()->create();
        $organization->featureFlags()->create(['feature_key' => OrganizationFeature::ClientRecords->value, 'enabled' => true]);
        $client = Client::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create(['requires_medical_profile' => true]);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()->forOrganization($organization)->forClient($client)->forService($service)->forSpecialist($specialist)->create();
        $event = ScenarioEvent::factory()->forOrganization($organization)->create([
            'event_name' => 'booking.created',
            'aggregate_id' => (string) $booking->id,
            'payload' => ['booking_id' => $booking->id, 'client_id' => $client->id],
        ]);

        return new ScenarioEvaluationContext($event, $booking, $client);
    }
}
