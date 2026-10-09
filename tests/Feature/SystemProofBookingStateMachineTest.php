<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Application\ConfirmBooking;
use App\Modules\Scheduling\Application\MarkBookingNoShow;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SystemProofBookingStateMachineTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('confirmationCases')]
    public function test_confirmation_visibility_and_backend_transition_agree_for_every_status_and_format(
        BookingStatus $status,
        VisitFormat $format,
        bool $allowed,
    ): void {
        [$actor, $booking] = $this->fixture($status, $format);
        $before = $booking->refresh()->getAttributes();
        $auditCount = AuditEvent::query()->count();
        $component = Livewire::test(ViewBooking::class, ['record' => $booking->getKey()]);

        if ($allowed) {
            $component->assertActionVisible('confirm')
                ->callAction('confirm')
                ->assertHasNoActionErrors();
            self::assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
            self::assertSame(2, $booking->event_version);
            self::assertSame(1, BookingEvent::query()->where('booking_id', $booking->getKey())->count());
            self::assertSame($auditCount + 1, AuditEvent::query()->count());
            $this->assertRejectedWithoutMutation($booking, fn () => app(ConfirmBooking::class)->handle($actor, $booking));
        } else {
            $component->assertActionHidden('confirm');
            $this->assertRejectedWithoutMutation($booking, fn () => app(ConfirmBooking::class)->handle($actor, $booking));
            self::assertSame($before, $booking->refresh()->getAttributes());
        }
    }

    #[DataProvider('terminalCases')]
    public function test_completion_and_no_show_visibility_and_direct_backend_rules_for_every_status(
        BookingStatus $status,
        string $action,
        bool $allowed,
    ): void {
        [$actor, $booking] = $this->fixture($status, VisitFormat::Online);
        $component = Livewire::test(ViewBooking::class, ['record' => $booking->getKey()]);
        $operation = $action === 'complete' ? CompleteBooking::class : MarkBookingNoShow::class;

        if ($allowed) {
            $component->assertActionVisible($action)->callAction($action)->assertHasNoActionErrors();
            self::assertSame($action === 'complete' ? BookingStatus::Completed : BookingStatus::NoShow, $booking->refresh()->status);
            self::assertSame(2, $booking->event_version);
            self::assertSame(1, BookingEvent::query()->where('booking_id', $booking->getKey())->count());
            $this->assertRejectedWithoutMutation($booking, fn () => app($operation)->handle($actor, $booking));
        } else {
            $component->assertActionHidden($action);
            $this->assertRejectedWithoutMutation($booking, fn () => app($operation)->handle($actor, $booking));
        }
    }

    public static function confirmationCases(): array
    {
        $cases = [];
        foreach (BookingStatus::cases() as $status) {
            foreach (VisitFormat::cases() as $format) {
                $cases[$status->value.' '.$format->value] = [
                    $status,
                    $format,
                    $status === BookingStatus::Requested && in_array($format, [VisitFormat::Office, VisitFormat::Online], true),
                ];
            }
        }

        return $cases;
    }

    public static function terminalCases(): array
    {
        $cases = [];
        foreach (BookingStatus::cases() as $status) {
            $cases[$status->value.' complete'] = [$status, 'complete', $status === BookingStatus::Confirmed];
            $cases[$status->value.' noShow'] = [$status, 'noShow', in_array($status, [BookingStatus::Requested, BookingStatus::Confirmed], true)];
        }

        return $cases;
    }

    private function fixture(BookingStatus $status, VisitFormat $format): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->forOrganization($organization)->create();
        foreach ([OrganizationFeature::ClientRecords, OrganizationFeature::ServiceCatalog] as $feature) {
            OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
                'feature_key' => $feature->value,
                'enabled' => true,
            ]);
        }
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create(['price_minor' => null, 'formats' => array_column(VisitFormat::cases(), 'value')]);
        $start = CarbonImmutable::now('UTC')->subHours(2);
        $booking = Booking::factory()->forClient($client)->forSpecialist($specialist)->forService($service)->create([
            'status' => $status,
            'visit_format' => $format,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'blocking_ends_at' => $start->addHour(),
            'event_version' => 1,
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($actor);

        return [$actor, $booking];
    }

    private function assertRejectedWithoutMutation(Booking $booking, callable $operation): void
    {
        $before = $booking->fresh()->getAttributes();
        $eventCount = BookingEvent::query()->where('booking_id', $booking->getKey())->count();
        $auditCount = AuditEvent::query()->count();
        try {
            $operation();
            self::fail('Invalid or repeated booking transition was accepted.');
        } catch (ValidationException $exception) {
            self::assertNotEmpty($exception->errors());
        }
        self::assertSame($before, $booking->fresh()->getAttributes());
        self::assertSame($eventCount, BookingEvent::query()->where('booking_id', $booking->getKey())->count());
        self::assertSame($auditCount, AuditEvent::query()->count());
    }
}
