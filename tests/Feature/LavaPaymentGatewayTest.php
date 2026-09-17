<?php

namespace Tests\Feature;

use App\Modules\Finance\Domain\Contracts\PaymentGatewayRegistry;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\GatewayInitiationRequest;
use App\Modules\Finance\Domain\ValueObjects\GatewayRefundRequest;
use App\Modules\Finance\Infrastructure\Fake\FakePaymentGateway;
use App\Modules\Finance\Infrastructure\Lava\LavaPaymentGateway;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class LavaPaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('providerCurrencies')]
    public function test_one_time_invoice_mapping_supports_lava_currencies(string $currency, string $amount): void
    {
        $organization = $this->organizationWithLavaCredential();
        Http::fake([
            'https://gate.lava.top/api/v3/invoice' => Http::response([
                'id' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/invoice',
            ], 201),
        ]);

        $result = app(LavaPaymentGateway::class)->initiate(new GatewayInitiationRequest(
            organizationId: $organization->getKey(),
            obligationId: 42,
            amountMinor: 1250,
            currency: CurrencyCode::from($currency),
            idempotencyKey: 'lava-request-1',
            buyerEmail: 'client@example.com',
            providerOfferId: '836b9fc5-7ae9-4a27-9642-592bc44072b7',
            successfulReturnUrl: 'https://portal.example/payment/return',
            failureReturnUrl: 'https://portal.example/payment/return',
            cancelReturnUrl: 'https://portal.example/payment/return',
        ));

        self::assertSame('lava', $result->gateway);
        self::assertSame('7ea82675-4ded-4133-95a7-a6efbaf165cc', $result->providerReference);
        self::assertSame('https://pay.lava.top/invoice', $result->checkoutUrl);
        Http::assertSent(function (Request $request) use ($currency, $amount): bool {
            return $request->url() === 'https://gate.lava.top/api/v3/invoice'
                && $request->header('X-Api-Key') === ['lava-api-key']
                && $request->data() === [
                    'email' => 'client@example.com',
                    'offerId' => '836b9fc5-7ae9-4a27-9642-592bc44072b7',
                    'currency' => $currency,
                    'amount' => $amount,
                    'periodicity' => 'ONE_TIME',
                    'successful_return_url' => 'https://portal.example/payment/return',
                    'failure_return_url' => 'https://portal.example/payment/return',
                    'cancel_return_url' => 'https://portal.example/payment/return',
                ];
        });
    }

    public function test_unsupported_lava_currency_fails_closed_before_http(): void
    {
        $organization = $this->organizationWithLavaCredential();
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        app(LavaPaymentGateway::class)->initiate(new GatewayInitiationRequest(
            organizationId: $organization->getKey(),
            obligationId: 42,
            amountMinor: 1250,
            currency: CurrencyCode::KZT,
            idempotencyKey: 'lava-unsupported-currency',
            buyerEmail: 'client@example.com',
            providerOfferId: '836b9fc5-7ae9-4a27-9642-592bc44072b7',
        ));
    }

    public function test_invalid_lava_offer_id_fails_closed_before_http(): void
    {
        $organization = $this->organizationWithLavaCredential();
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        app(LavaPaymentGateway::class)->initiate(new GatewayInitiationRequest(
            organizationId: $organization->getKey(),
            obligationId: 42,
            amountMinor: 1250,
            currency: CurrencyCode::USD,
            idempotencyKey: 'lava-invalid-offer',
            buyerEmail: 'client@example.com',
            providerOfferId: 'not-a-uuid',
        ));
    }

    public function test_lava_invoice_lookup_maps_completed_and_preserves_amount_currency(): void
    {
        $organization = $this->organizationWithLavaCredential();
        Http::fake([
            'https://gate.lava.top/api/v1/invoices/7ea82675-4ded-4133-95a7-a6efbaf165cc' => Http::response([
                'id' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
                'status' => 'completed',
                'amountTotal' => ['amount' => 12.5, 'currency' => 'USD'],
            ]),
        ]);

        $result = app(LavaPaymentGateway::class)->reconcile(
            '7ea82675-4ded-4133-95a7-a6efbaf165cc',
            $organization->getKey(),
        );

        self::assertSame('settled', $result->status);
        self::assertSame(1250, $result->amountMinor);
        self::assertSame(CurrencyCode::USD, $result->currency);
    }

    public function test_lava_invoice_lookup_rejects_a_different_response_contract_reference(): void
    {
        $organization = $this->organizationWithLavaCredential();
        Http::fake([
            'https://gate.lava.top/api/v1/invoices/7ea82675-4ded-4133-95a7-a6efbaf165cc' => Http::response([
                'id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                'status' => 'completed',
                'receipt' => ['amount' => 12.5, 'currency' => 'USD'],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        app(LavaPaymentGateway::class)->reconcile(
            '7ea82675-4ded-4133-95a7-a6efbaf165cc',
            $organization->getKey(),
        );
    }

    public function test_lava_does_not_offer_refund_initiation(): void
    {
        $gateway = app(LavaPaymentGateway::class);

        $this->expectException(LogicException::class);
        $gateway->refund(new GatewayRefundRequest(
            organizationId: 1,
            providerReference: '7ea82675-4ded-4133-95a7-a6efbaf165cc',
            amountMinor: 1250,
            currency: CurrencyCode::USD,
            idempotencyKey: 'lava-refund',
        ));
    }

    public function test_registry_resolves_fake_and_lava_without_global_lava_binding(): void
    {
        $registry = app(PaymentGatewayRegistry::class);

        self::assertInstanceOf(FakePaymentGateway::class, $registry->resolve('fake'));
        self::assertInstanceOf(LavaPaymentGateway::class, $registry->resolve('lava'));
    }

    public static function providerCurrencies(): array
    {
        return [
            'RUB' => ['RUB', '12.50'],
            'USD' => ['USD', '12.50'],
            'EUR' => ['EUR', '12.50'],
        ];
    }

    private function organizationWithLavaCredential(): Organization
    {
        config()->set('payments.lava.base_url', 'https://gate.lava.top');
        config()->set('payments.lava.credential_name', 'default');
        $organization = Organization::factory()->create();
        $credential = OrganizationCredential::factory()->forOrganization($organization)->make([
            'provider' => 'lava',
            'credential_name' => 'default',
            'status' => CredentialStatus::Active->value,
        ]);
        $credential->forceFill([
            'credentials' => ['api_key' => 'lava-api-key', 'webhook_api_key' => 'lava-webhook-key'],
        ])->save();

        return $organization;
    }
}
