<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Application\ConfirmBooking;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Enums\ServicePaymentRequirement;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Services\Domain\ValueObjects\ServiceConfiguration;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ServicePaymentRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_configuration_uses_typed_payment_requirement(): void
    {
        $configuration = ServiceConfiguration::from([
            'name' => 'Prepaid session',
            'summary' => 'Session summary',
            'catalog_type' => 'service',
            'price_minor' => 800000,
            'price_currency' => 'RUB',
            'payment_policy' => 'legacy free text',
            'payment_requirement' => ServicePaymentRequirement::PrepayFull->value,
        ]);

        self::assertSame(ServicePaymentRequirement::PrepayFull, $configuration->paymentRequirement);
        self::assertSame('legacy free text', $configuration->paymentPolicy);
    }

    public function test_legacy_payment_policy_does_not_enable_pre_service_obligation(): void
    {
        [$organization, $admin, $booking] = $this->bookingWithService([
            'payment_policy' => 'prepay',
            'payment_requirement' => ServicePaymentRequirement::Postpay->value,
        ]);

        app(OrganizationContext::class)->set($organization);

        app(ConfirmBooking::class)->handle($admin, $booking);

        self::assertDatabaseMissing('financial_obligations', [
            'organization_id' => $organization->getKey(),
            'booking_id' => $booking->getKey(),
        ]);
    }

    public function test_prepay_full_creates_obligation_when_booking_is_confirmed(): void
    {
        [$organization, $admin, $booking] = $this->bookingWithService([
            'payment_requirement' => ServicePaymentRequirement::PrepayFull->value,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'blocking_ends_at' => now()->subHour(),
        ]);

        app(OrganizationContext::class)->set($organization);

        app(ConfirmBooking::class)->handle($admin, $booking);

        $obligation = FinancialObligation::query()
            ->where('organization_id', $organization->getKey())
            ->where('booking_id', $booking->getKey())
            ->first();

        self::assertNotNull($obligation);
        self::assertSame(ServicePaymentRequirement::PrepayFull->value, $obligation->price_snapshot['payment_requirement']);

        app(CompleteBooking::class)->handle($admin, $booking->fresh());

        self::assertSame(1, FinancialObligation::query()
            ->where('organization_id', $organization->getKey())
            ->where('booking_id', $booking->getKey())
            ->count());
    }

    public function test_postpay_creates_obligation_after_completion(): void
    {
        [$organization, $admin, $booking] = $this->bookingWithService([
            'payment_requirement' => ServicePaymentRequirement::Postpay->value,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'blocking_ends_at' => now()->subHour(),
        ]);

        app(OrganizationContext::class)->set($organization);

        app(ConfirmBooking::class)->handle($admin, $booking);

        self::assertDatabaseMissing('financial_obligations', [
            'organization_id' => $organization->getKey(),
            'booking_id' => $booking->getKey(),
        ]);

        app(CompleteBooking::class)->handle($admin, $booking->fresh());

        self::assertDatabaseHas('financial_obligations', [
            'organization_id' => $organization->getKey(),
            'booking_id' => $booking->getKey(),
        ]);
    }

    /** @param array<string, mixed> $serviceAttributes */
    private function bookingWithService(array $serviceAttributes = []): array
    {
        $bookingAttributes = array_intersect_key($serviceAttributes, array_flip(['starts_at', 'ends_at', 'blocking_ends_at']));
        $serviceAttributes = array_diff_key($serviceAttributes, $bookingAttributes);
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => 800000,
            'price_currency' => 'RUB',
            ...$serviceAttributes,
        ]);
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => 'requested',
                ...$bookingAttributes,
            ]);

        app(OrganizationContext::class)->set($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => 'RUB',
            'allowed_currencies' => ['RUB'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);

        return [$organization, $admin, $booking];
    }
}
