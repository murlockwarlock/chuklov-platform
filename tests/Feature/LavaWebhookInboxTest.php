<?php

namespace Tests\Feature;

use App\Modules\Finance\Application\ReceiveLavaWebhook;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventStatus;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventType;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;
use App\Modules\Finance\Infrastructure\Lava\LavaWebhookAuthenticator;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

final class LavaWebhookInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_payment_success_is_durable_pending_link(): void
    {
        $organization = $this->organizationWithWebhookCredential();
        $payload = $this->paymentPayload();

        $event = app(ReceiveLavaWebhook::class)->handle($organization->getKey(), $payload);

        self::assertSame(PaymentGatewayEventStatus::PendingLink, $event->processing_status);
        self::assertSame(PaymentGatewayEventType::Settlement, $event->event_type);
        self::assertSame('lava:payment.success:7ea82675-4ded-4133-95a7-a6efbaf165cc', $event->provider_event_key);
        self::assertNull($event->gateway_transaction_id);
        self::assertSame(1, PaymentGatewayEvent::query()->count());
    }

    public function test_same_payment_payload_replay_is_idempotent_and_conflict_is_quarantined(): void
    {
        $organization = $this->organizationWithWebhookCredential();
        $payload = $this->paymentPayload();
        $first = app(ReceiveLavaWebhook::class)->handle($organization->getKey(), $payload);
        $replayed = app(ReceiveLavaWebhook::class)->handle($organization->getKey(), $payload);

        self::assertSame($first->getKey(), $replayed->getKey());
        self::assertSame(1, PaymentGatewayEvent::query()->count());
        self::assertSame(PaymentGatewayEventStatus::PendingLink, $replayed->processing_status);

        $conflict = $payload;
        $conflict['amount'] = 99.99;
        $conflicted = app(ReceiveLavaWebhook::class)->handle($organization->getKey(), $conflict);

        self::assertSame($first->getKey(), $conflicted->getKey());
        self::assertSame(PaymentGatewayEventStatus::ReconciliationRequired, $conflicted->processing_status);
        self::assertSame('provider_event_key_payload_conflict', $conflicted->reconciliation_reason);
        self::assertSame(1, PaymentGatewayEvent::query()->count());
    }

    public function test_refund_and_chargeback_are_visible_reconciliation_events_without_automatic_linking(): void
    {
        $organization = $this->organizationWithWebhookCredential();
        $refund = app(ReceiveLavaWebhook::class)->handle($organization->getKey(), [
            'event_id' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
            'event_type' => 'refund.success',
            'created_at' => '2026-09-17T10:00:00Z',
            'data' => ['amount' => 12.5, 'currency' => 'USD', 'customer_email' => 'client@example.com'],
        ]);
        $chargeback = app(ReceiveLavaWebhook::class)->handle($organization->getKey(), [
            'event_id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            'event_type' => 'chargeback.initiated',
            'created_at' => '2026-09-17T10:01:00Z',
            'data' => ['amount' => 12.5, 'currency' => 'USD', 'customer_email' => 'client@example.com'],
        ]);

        self::assertSame(PaymentGatewayEventType::Refund, $refund->event_type);
        self::assertSame(PaymentGatewayEventType::Chargeback, $chargeback->event_type);
        self::assertSame(PaymentGatewayEventStatus::ReconciliationRequired, $refund->processing_status);
        self::assertSame(PaymentGatewayEventStatus::ReconciliationRequired, $chargeback->processing_status);
        self::assertNull($refund->provider_reference);
        self::assertNull($chargeback->gateway_transaction_id);
        self::assertSame(2, PaymentGatewayEvent::query()->count());
    }

    public function test_recurring_lava_webhook_never_becomes_an_automatic_payment_event(): void
    {
        $organization = $this->organizationWithWebhookCredential();
        $event = app(ReceiveLavaWebhook::class)->handle($organization->getKey(), [
            'eventType' => 'subscription.recurring.payment.success',
            'product' => ['id' => 'd31384b8-e412-4be5-a2ec-297ae6666c8f', 'title' => 'Subscription'],
            'contractId' => '04f152b7-63ec-46ff-958e-8a6f5869acd6',
            'buyer' => ['email' => 'client@example.com'],
            'amount' => 12.5,
            'currency' => 'USD',
            'timestamp' => '2026-09-17T10:00:00Z',
            'status' => 'subscription-active',
            'errorMessage' => '',
        ]);

        self::assertSame(PaymentGatewayEventType::Unknown, $event->event_type);
        self::assertSame(PaymentGatewayEventStatus::ReconciliationRequired, $event->processing_status);
        self::assertNull($event->gateway_transaction_id);
    }

    public function test_webhook_authentication_requires_one_matching_organization_credential(): void
    {
        $organization = $this->organizationWithWebhookCredential();
        $request = Request::create('/webhooks/lava', 'POST', [], [], [], [
            'HTTP_X_API_KEY' => 'lava-webhook-key',
        ]);

        self::assertSame($organization->getKey(), app(LavaWebhookAuthenticator::class)->authenticate($request));
        $request->headers->set('X-Api-Key', 'wrong-key');
        self::assertNull(app(LavaWebhookAuthenticator::class)->authenticate($request));
    }

    public function test_duplicate_webhook_secret_across_organizations_fails_closed(): void
    {
        $first = $this->organizationWithWebhookCredential();
        $second = $this->organizationWithWebhookCredential();
        $request = Request::create('/webhooks/lava', 'POST', [], [], [], [
            'HTTP_X_API_KEY' => 'lava-webhook-key',
        ]);

        self::assertNotSame($first->getKey(), $second->getKey());
        self::assertNull(app(LavaWebhookAuthenticator::class)->authenticate($request));
    }

    public function test_webhook_route_authenticates_and_persists_before_responding(): void
    {
        $organization = $this->organizationWithWebhookCredential();

        $this->withHeader('X-Api-Key', 'lava-webhook-key')
            ->postJson('/webhooks/lava', $this->paymentPayload())
            ->assertNoContent();

        self::assertDatabaseHas('payment_gateway_events', [
            'organization_id' => $organization->getKey(),
            'provider_event_key' => 'lava:payment.success:7ea82675-4ded-4133-95a7-a6efbaf165cc',
            'processing_status' => PaymentGatewayEventStatus::PendingLink->value,
        ]);
    }

    public function test_webhook_route_excludes_laravel_request_forgery_middleware(): void
    {
        $route = Route::getRoutes()->getByName('webhooks.lava');

        self::assertNotNull($route);
        self::assertContains(PreventRequestForgery::class, $route->excludedMiddleware());
    }

    public function test_lava_api_key_can_authenticate_webhooks_without_a_separate_webhook_key(): void
    {
        $organization = $this->organizationWithWebhookCredential();
        $credential = OrganizationCredential::query()
            ->where('organization_id', $organization->getKey())
            ->where('provider', 'lava')
            ->firstOrFail();
        $credential->forceFill([
            'credentials' => ['api_key' => 'lava-webhook-key'],
        ])->save();

        $request = Request::create('/webhooks/lava', 'POST', [], [], [], [
            'HTTP_X_API_KEY' => 'lava-webhook-key',
        ]);

        self::assertSame($organization->getKey(), app(LavaWebhookAuthenticator::class)->authenticate($request));
    }

    public function test_invalid_payload_is_not_accepted_as_a_payment_event(): void
    {
        $organization = $this->organizationWithWebhookCredential();

        $this->expectException(InvalidArgumentException::class);
        app(ReceiveLavaWebhook::class)->handle($organization->getKey(), ['eventType' => 'payment.success']);
    }

    private function organizationWithWebhookCredential(): Organization
    {
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

    private function paymentPayload(): array
    {
        return [
            'eventType' => 'payment.success',
            'product' => ['id' => 'd31384b8-e412-4be5-a2ec-297ae6666c8f', 'title' => 'Course'],
            'contractId' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
            'buyer' => ['email' => 'client@example.com'],
            'amount' => 12.5,
            'currency' => 'USD',
            'timestamp' => '2026-09-17T10:00:00Z',
            'status' => 'completed',
            'errorMessage' => '',
        ];
    }
}
