<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\Application\DrainPaymentGatewayEvents;
use App\Modules\Finance\Application\ProcessPaymentGatewayEvent;
use App\Modules\Finance\Application\ReceiveLavaWebhook;
use App\Modules\Finance\Application\ReprocessPendingPaymentGatewayEvents;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventStatus;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PaymentGatewayEventProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_success_before_reference_link_is_processed_after_reference_save(): void
    {
        [$organization, $obligation] = $this->fixture();
        $transaction = $this->transaction($organization, $obligation, null, PaymentGatewayStatus::Initiating);
        $event = app(ReceiveLavaWebhook::class)->handle($organization->getKey(), $this->paymentPayload());

        self::assertSame(PaymentGatewayEventStatus::PendingLink, $event->processing_status);

        $transaction->forceFill([
            'provider_reference' => $this->providerReference(),
            'status' => PaymentGatewayStatus::Pending->value,
        ])->save();
        app(DrainPaymentGatewayEvents::class)->handle($organization->getKey(), 'lava', $this->providerReference());

        self::assertSame(PaymentGatewayStatus::Settled, $transaction->fresh()->status);
        self::assertSame(PaymentGatewayEventStatus::Processed, $event->fresh()->processing_status);
        self::assertSame(1, DB::table('financial_ledger_entries')->where('organization_id', $organization->getKey())->count());
    }

    public function test_reprocessor_recovers_event_after_reference_save_before_drain(): void
    {
        [$organization, $obligation] = $this->fixture();
        $event = app(ReceiveLavaWebhook::class)->handle($organization->getKey(), $this->paymentPayload());
        $this->transaction($organization, $obligation, $this->providerReference(), PaymentGatewayStatus::Pending);

        self::assertSame(PaymentGatewayEventStatus::PendingLink, $event->fresh()->processing_status);
        self::assertSame(1, app(ReprocessPendingPaymentGatewayEvents::class)->handle());
        self::assertSame(PaymentGatewayEventStatus::Processed, $event->fresh()->processing_status);
    }

    public function test_amount_mismatch_is_quarantined_without_settlement(): void
    {
        [$organization, $obligation] = $this->fixture();
        $transaction = $this->transaction($organization, $obligation, $this->providerReference(), PaymentGatewayStatus::Pending);
        $payload = $this->paymentPayload();
        $payload['amount'] = 99.99;
        $event = app(ReceiveLavaWebhook::class)->handle($organization->getKey(), $payload);

        app(DrainPaymentGatewayEvents::class)->handle($organization->getKey(), 'lava', $this->providerReference());

        self::assertSame(PaymentGatewayEventStatus::ReconciliationRequired, $event->fresh()->processing_status);
        self::assertSame('amount_or_currency_mismatch', $event->fresh()->reconciliation_reason);
        self::assertSame(PaymentGatewayStatus::Pending, $transaction->fresh()->status);
        self::assertSame(0, DB::table('financial_ledger_entries')->where('organization_id', $organization->getKey())->count());
    }

    public function test_failure_event_marks_pending_transaction_failed_without_ledger_entry(): void
    {
        [$organization, $obligation] = $this->fixture();
        $transaction = $this->transaction($organization, $obligation, $this->providerReference(), PaymentGatewayStatus::Pending);
        $payload = $this->paymentPayload();
        $payload['eventType'] = 'payment.failed';
        $payload['status'] = 'failed';
        $event = app(ReceiveLavaWebhook::class)->handle($organization->getKey(), $payload);

        app(DrainPaymentGatewayEvents::class)->handle($organization->getKey(), 'lava', $this->providerReference());

        self::assertSame(PaymentGatewayEventStatus::Processed, $event->fresh()->processing_status);
        self::assertSame(PaymentGatewayStatus::Failed, $transaction->fresh()->status);
        self::assertSame(0, DB::table('financial_ledger_entries')->where('organization_id', $organization->getKey())->count());
    }

    public function test_stale_pending_link_becomes_visible_reconciliation_required(): void
    {
        [$organization] = $this->fixture();
        config()->set('payments.events.max_attempts', 1);
        $event = app(ReceiveLavaWebhook::class)->handle($organization->getKey(), $this->paymentPayload());

        app(ProcessPaymentGatewayEvent::class)->handle($organization->getKey(), $event->getKey());
        $stale = app(ProcessPaymentGatewayEvent::class)->handle($organization->getKey(), $event->getKey());

        self::assertSame(PaymentGatewayEventStatus::ReconciliationRequired, $stale->processing_status);
        self::assertSame('pending_link_stale', $stale->reconciliation_reason);
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

    private function transaction(
        Organization $organization,
        FinancialObligation $obligation,
        ?string $providerReference,
        PaymentGatewayStatus $status,
    ): PaymentGatewayTransaction {
        $transaction = new PaymentGatewayTransaction;
        $transaction->forceFill([
            'organization_id' => $organization->getKey(),
            'obligation_id' => $obligation->getKey(),
            'gateway' => 'lava',
            'idempotency_key' => 'lava-test-'.$obligation->getKey().'-'.uniqid(),
            'request_hash' => hash('sha256', 'lava-test'),
            'provider_reference' => $providerReference,
            'amount_minor' => 10000,
            'currency' => 'USD',
            'settlement_amount_minor' => 10000,
            'settlement_currency' => 'USD',
            'status' => $status->value,
            'initiated_at' => now(),
        ])->save();

        return $transaction->refresh();
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

    private function paymentPayload(): array
    {
        return [
            'eventType' => 'payment.success',
            'contractId' => $this->providerReference(),
            'buyer' => ['email' => 'client@example.com'],
            'amount' => 100,
            'currency' => 'USD',
            'timestamp' => '2026-09-17T10:00:00Z',
            'status' => 'completed',
        ];
    }

    private function providerReference(): string
    {
        return '7ea82675-4ded-4133-95a7-a6efbaf165cc';
    }
}
