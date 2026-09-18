<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\Application\CreateGatewayPaymentAttempt;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class GatewayPaymentInitiationTest extends TestCase
{
    use RefreshDatabase;

    public function test_lava_initiation_persists_local_state_and_is_idempotent(): void
    {
        [$organization, $obligation] = $this->fixture();
        Http::fake([
            '*' => Http::response([
                'id' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/invoice',
            ], 201),
        ]);

        $service = app(CreateGatewayPaymentAttempt::class);
        $first = $service->handle(
            organization: $organization,
            obligation: $obligation,
            gatewayName: 'lava',
            idempotencyKey: 'lava-init-1',
            buyerEmail: 'client@example.com',
            providerOfferId: '836b9fc5-7ae9-4a27-9642-592bc44072b7',
            successfulReturnUrl: 'https://portal.example/payment/return',
            failureReturnUrl: 'https://portal.example/payment/return',
            cancelReturnUrl: 'https://portal.example/payment/return',
        );
        $second = $service->handle(
            organization: $organization,
            obligation: $obligation,
            gatewayName: 'lava',
            idempotencyKey: 'lava-init-1',
            buyerEmail: 'client@example.com',
            providerOfferId: '836b9fc5-7ae9-4a27-9642-592bc44072b7',
            successfulReturnUrl: 'https://portal.example/payment/return',
            failureReturnUrl: 'https://portal.example/payment/return',
            cancelReturnUrl: 'https://portal.example/payment/return',
        );

        self::assertSame($first->getKey(), $second->getKey());
        self::assertSame(PaymentGatewayStatus::Pending, $first->status);
        self::assertSame('7ea82675-4ded-4133-95a7-a6efbaf165cc', $first->provider_reference);
        self::assertSame('https://pay.lava.top/invoice', $first->checkout_url);
        self::assertSame(1, PaymentGatewayTransaction::query()->count());
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->data()['periodicity'] === 'ONE_TIME'
                && $request->data()['amount'] === '100.00'
                && $request->data()['currency'] === 'USD';
        });
    }

    public function test_ambiguous_lava_creation_becomes_unknown_and_is_not_blindly_retried(): void
    {
        [$organization, $obligation] = $this->fixture();
        Http::fake([
            '*' => Http::response(['message' => 'temporary upstream failure'], 500),
        ]);
        $service = app(CreateGatewayPaymentAttempt::class);

        try {
            $service->handle(
                organization: $organization,
                obligation: $obligation,
                gatewayName: 'lava',
                idempotencyKey: 'lava-init-ambiguous',
                buyerEmail: 'client@example.com',
                providerOfferId: '836b9fc5-7ae9-4a27-9642-592bc44072b7',
            );
            self::fail('The failed Lava request should be surfaced.');
        } catch (RuntimeException) {
            self::assertSame(PaymentGatewayStatus::Unknown, PaymentGatewayTransaction::query()->sole()->status);
        }

        Http::fake([
            '*' => Http::response([
                'id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/invoice-2',
            ], 201),
        ]);
        $again = $service->handle(
            organization: $organization,
            obligation: $obligation,
            gatewayName: 'lava',
            idempotencyKey: 'lava-init-ambiguous',
            buyerEmail: 'client@example.com',
            providerOfferId: '836b9fc5-7ae9-4a27-9642-592bc44072b7',
        );

        self::assertSame(PaymentGatewayStatus::Unknown, $again->status);
        self::assertNull($again->provider_reference);
        Http::assertSentCount(0);
    }

    public function test_existing_initiating_idempotency_state_returns_without_a_second_external_creation(): void
    {
        [$organization, $obligation] = $this->fixture();
        Http::fake([
            '*' => Http::response([
                'id' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/invoice',
            ], 201),
        ]);
        $service = app(CreateGatewayPaymentAttempt::class);
        $transaction = $service->handle(
            organization: $organization,
            obligation: $obligation,
            gatewayName: 'lava',
            idempotencyKey: 'lava-init-in-progress',
            buyerEmail: 'client@example.com',
            providerOfferId: '836b9fc5-7ae9-4a27-9642-592bc44072b7',
        );
        $transaction->forceFill(['status' => PaymentGatewayStatus::Initiating->value, 'provider_reference' => null])->save();

        $again = $service->handle(
            organization: $organization,
            obligation: $obligation,
            gatewayName: 'lava',
            idempotencyKey: 'lava-init-in-progress',
            buyerEmail: 'client@example.com',
            providerOfferId: '836b9fc5-7ae9-4a27-9642-592bc44072b7',
        );

        self::assertSame($transaction->getKey(), $again->getKey());
        self::assertSame(PaymentGatewayStatus::Initiating, $again->status);
        Http::assertSentCount(1);
    }

    public function test_lava_configuration_failure_has_safe_state_and_one_operational_event(): void
    {
        [$organization, $obligation] = $this->fixture();
        OrganizationCredential::query()
            ->where('organization_id', $organization->getKey())
            ->where('provider', 'lava')
            ->update(['status' => CredentialStatus::Disabled->value]);
        Http::fake();

        try {
            app(CreateGatewayPaymentAttempt::class)->handle(
                organization: $organization,
                obligation: $obligation,
                gatewayName: 'lava',
                idempotencyKey: 'lava-init-missing-credential',
                buyerEmail: 'client@example.com',
                providerOfferId: '836b9fc5-7ae9-4a27-9642-592bc44072b7',
            );
            self::fail('A missing credential must stop checkout initiation.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The active Lava API credential is not configured.', $exception->getMessage());
        }

        $transaction = PaymentGatewayTransaction::query()->sole();
        self::assertSame(PaymentGatewayStatus::Unknown, $transaction->status);
        self::assertStringNotContainsString('lava-api-key', (string) $transaction->last_error);
        self::assertSame(
            1,
            ScenarioEvent::query()->where('event_name', ScenarioEventType::PaymentInitiationUnavailable->value)->count(),
        );
        self::assertStringContainsString(
            'API-ключ Lava не настроен',
            (string) ScenarioEvent::query()->where('event_name', ScenarioEventType::PaymentInitiationUnavailable->value)->sole()->payload['reason'],
        );

        $again = app(CreateGatewayPaymentAttempt::class)->handle(
            organization: $organization,
            obligation: $obligation,
            gatewayName: 'lava',
            idempotencyKey: 'lava-init-missing-credential',
            buyerEmail: 'client@example.com',
            providerOfferId: '836b9fc5-7ae9-4a27-9642-592bc44072b7',
        );

        self::assertSame($transaction->getKey(), $again->getKey());
        self::assertSame(
            1,
            ScenarioEvent::query()->where('event_name', ScenarioEventType::PaymentInitiationUnavailable->value)->count(),
        );
        Http::assertNothingSent();
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $this->organizationCredential($organization);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => 10000,
            'price_currency' => 'USD',
        ]);
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => now()->subHours(2),
                'ends_at' => now()->subHour(),
                'blocking_ends_at' => now()->subHour(),
            ]);
        app(OrganizationContext::class)->set($organization);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);
        app(CompleteBooking::class)->handle($admin, $booking);

        return [$organization, FinancialObligation::query()->where('booking_id', $booking->getKey())->firstOrFail()];
    }

    private function organizationCredential(Organization $organization): void
    {
        $credential = OrganizationCredential::factory()->forOrganization($organization)->make([
            'provider' => 'lava',
            'credential_name' => 'default',
            'status' => CredentialStatus::Active->value,
        ]);
        $credential->forceFill([
            'credentials' => ['api_key' => 'lava-api-key', 'webhook_api_key' => 'lava-webhook-key'],
        ])->save();
    }
}
