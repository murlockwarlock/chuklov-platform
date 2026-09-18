<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Infrastructure\Database\DatabaseNotificationChannel;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Models\FulfillmentEvent;
use App\Modules\Commerce\Domain\Models\Purchase;
use App\Modules\Commerce\Domain\Models\PurchaseFulfillment;
use App\Modules\Commerce\Domain\Models\PurchaseItem;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventStatus;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventType;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Enums\ProviderVerificationStatus;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\EnsureOperationalNotificationDefaults;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class PaymentScenarioNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_success_materializes_one_safe_client_notification(): void
    {
        [$organization, $client, $obligation, $ledgerEntry] = $this->paymentFixture();
        ClientChannelIdentity::factory()->forClient($client)->create([
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'external_id' => 'client-payment-chat',
        ]);
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        $telegram = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$telegram]));

        $event = app(RecordScenarioEvent::class)->paymentSucceeded(
            $obligation,
            $ledgerEntry,
            CarbonImmutable::now(),
        );
        $duplicate = app(RecordScenarioEvent::class)->paymentSucceeded(
            $obligation,
            $ledgerEntry,
            CarbonImmutable::now(),
        );

        self::assertSame($event->getKey(), $duplicate->getKey());
        $this->materializeAndDeliver($event);
        $this->materializeAndDeliver($duplicate);

        self::assertSame(1, ScenarioEvent::query()->where('event_name', ScenarioEventType::PaymentSucceeded->value)->count());
        self::assertCount(1, $telegram->messages);
        self::assertStringContainsString('35.00 USD', $telegram->messages[0]->body);
        self::assertStringContainsString('Сеанс восстановления', $telegram->messages[0]->body);
        self::assertStringNotContainsString('provider_event_key', $telegram->messages[0]->body);
        self::assertStringNotContainsString('lava-secret', $telegram->messages[0]->body);
    }

    public function test_reconciliation_alert_is_visible_to_finance_users_without_provider_payload(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        OrganizationChannelIdentity::factory()->forUser($admin)->verified()->create(['external_id' => 'finance-chat']);
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        $telegram = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([
            app(DatabaseNotificationChannel::class),
            $telegram,
        ]));

        $providerKey = 'lava:refund.success:refund-1';
        $event = new PaymentGatewayEvent;
        $event->forceFill([
            'organization_id' => $organization->getKey(),
            'gateway_transaction_id' => null,
            'gateway' => 'lava',
            'event_type' => PaymentGatewayEventType::Refund->value,
            'provider_event_id' => 'refund-1',
            'provider_event_key' => $providerKey,
            'provider_reference' => null,
            'verification_status' => ProviderVerificationStatus::Verified->value,
            'processing_status' => PaymentGatewayEventStatus::ReconciliationRequired->value,
            'amount_minor' => 3500,
            'currency' => 'USD',
            'payload_hash' => hash('sha256', 'refund-1'),
            'payload' => ['secret' => 'lava-secret'],
            'reconciliation_reason' => 'refund_manual_reconciliation_required',
            'created_at' => now(),
        ])->save();

        $scenarioEvent = app(RecordScenarioEvent::class)->paymentReconciliationRequired(
            $event->refresh(),
            CarbonImmutable::now(),
        );
        $this->materializeAndDeliver($scenarioEvent);

        self::assertCount(1, $telegram->messages);
        self::assertStringContainsString('событие возврата', $telegram->messages[0]->body);
        self::assertStringNotContainsString($providerKey, $telegram->messages[0]->body);
        self::assertStringNotContainsString('lava-secret', $telegram->messages[0]->body);
        self::assertSame(1, $admin->fresh()->notifications()->count());
        self::assertStringContainsString(
            'событие возврата',
            (string) ($admin->fresh()->notifications()->sole()->data['body'] ?? ''),
        );
    }

    public function test_authoritative_payment_failure_sends_safe_client_notification_once(): void
    {
        [$organization, $client, $obligation] = $this->paymentFixture();
        ClientChannelIdentity::factory()->forClient($client)->create([
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'external_id' => 'client-payment-failure-chat',
        ]);
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        $telegram = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$telegram]));

        $transaction = new PaymentGatewayTransaction;
        $transaction->forceFill([
            'organization_id' => $organization->getKey(),
            'obligation_id' => $obligation->getKey(),
            'gateway' => 'lava',
            'idempotency_key' => 'notification-payment-failure',
            'request_hash' => str_repeat('a', 64),
            'provider_reference' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
            'amount_minor' => 3500,
            'currency' => 'USD',
            'settlement_amount_minor' => 3500,
            'settlement_currency' => 'USD',
            'status' => PaymentGatewayStatus::Pending->value,
            'initiated_at' => now(),
        ])->save();
        $providerEvent = new PaymentGatewayEvent;
        $providerEvent->forceFill([
            'organization_id' => $organization->getKey(),
            'gateway_transaction_id' => $transaction->getKey(),
            'gateway' => 'lava',
            'event_type' => PaymentGatewayEventType::Failure->value,
            'provider_event_id' => null,
            'provider_event_key' => 'lava:payment.failed:7ea82675-4ded-4133-95a7-a6efbaf165cc',
            'provider_reference' => $transaction->provider_reference,
            'verification_status' => ProviderVerificationStatus::Verified->value,
            'processing_status' => PaymentGatewayEventStatus::Processed->value,
            'amount_minor' => 3500,
            'currency' => 'USD',
            'payload_hash' => hash('sha256', 'payment-failure'),
            'payload' => [],
            'created_at' => now(),
        ])->save();

        $event = app(RecordScenarioEvent::class)->paymentFailed(
            $providerEvent->refresh(),
            $transaction->refresh(),
            CarbonImmutable::now(),
        );
        $duplicate = app(RecordScenarioEvent::class)->paymentFailed(
            $providerEvent->refresh(),
            $transaction->refresh(),
            CarbonImmutable::now(),
        );
        $this->materializeAndDeliver($event);
        $this->materializeAndDeliver($duplicate);

        self::assertSame($event->getKey(), $duplicate->getKey());
        self::assertCount(1, $telegram->messages);
        self::assertStringContainsString('Оплату завершить не удалось', $telegram->messages[0]->body);
        self::assertStringNotContainsString('7ea82675-4ded-4133-95a7-a6efbaf165cc', $telegram->messages[0]->body);
        self::assertStringNotContainsString('provider_event_key', $telegram->messages[0]->body);
    }

    public function test_payment_initiation_configuration_alert_reaches_finance_users_without_client_message(): void
    {
        [$organization, , $obligation] = $this->paymentFixture();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        OrganizationChannelIdentity::factory()->forUser($admin)->verified()->create(['external_id' => 'finance-config-chat']);
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        $telegram = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([
            app(DatabaseNotificationChannel::class),
            $telegram,
        ]));

        $event = app(RecordScenarioEvent::class)->paymentInitiationUnavailable(
            organizationId: $organization->getKey(),
            gateway: 'lava',
            reason: 'missing_credential',
            occurredAt: CarbonImmutable::now(),
            obligation: $obligation,
            deduplicationKey: 'gateway:lava:missing_credential',
        );
        $this->materializeAndDeliver($event);

        self::assertCount(1, $telegram->messages);
        self::assertSame('finance-config-chat', $telegram->messages[0]->recipientExternalId);
        self::assertStringContainsString('API-ключ Lava не настроен', $telegram->messages[0]->body);
        self::assertSame(1, $admin->fresh()->notifications()->count());
    }

    public function test_fulfillment_failure_notifies_client_and_finance_once(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Клиент курса']);
        ClientChannelIdentity::factory()->forClient($client)->create([
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'external_id' => 'client-fulfillment-chat',
        ]);
        OrganizationChannelIdentity::factory()->forUser($admin)->verified()->create(['external_id' => 'finance-fulfillment-chat']);
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        $telegram = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([
            app(DatabaseNotificationChannel::class),
            $telegram,
        ]));

        $purchase = new Purchase;
        $purchase->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'status' => 'paid',
            'total_amount_minor' => 3500,
            'currency' => 'USD',
            'purchase_snapshot' => ['name' => 'Курс восстановления'],
            'paid_at' => now(),
        ])->save();
        $item = new PurchaseItem;
        $item->forceFill([
            'organization_id' => $organization->getKey(),
            'purchase_id' => $purchase->getKey(),
            'sellable_type' => Service::class,
            'sellable_id' => 1,
            'quantity' => 1,
            'amount_minor' => 3500,
            'currency' => 'USD',
            'product_snapshot' => ['name' => 'Курс восстановления'],
        ])->save();
        $fulfillment = new PurchaseFulfillment;
        $fulfillment->forceFill([
            'organization_id' => $organization->getKey(),
            'purchase_item_id' => $item->getKey(),
            'provider_type' => 'manual',
            'status' => CommerceFulfillmentStatus::Failed->value,
            'attempts' => 1,
            'last_error' => 'internal-provider-secret',
        ])->save();
        $transition = new FulfillmentEvent;
        $transition->forceFill([
            'organization_id' => $organization->getKey(),
            'fulfillment_id' => $fulfillment->getKey(),
            'from_status' => CommerceFulfillmentStatus::Processing->value,
            'to_status' => CommerceFulfillmentStatus::Failed->value,
            'actor_user_id' => null,
            'metadata' => ['source' => 'test'],
        ])->save();

        $scenarioEvent = app(RecordScenarioEvent::class)->fulfillmentFailed(
            $fulfillment->refresh(),
            $transition->refresh(),
            'provider_failed',
            CarbonImmutable::now(),
        );
        $this->materializeAndDeliver($scenarioEvent);
        $this->materializeAndDeliver($scenarioEvent);

        self::assertSame(1, ScenarioEvent::query()->where('event_name', ScenarioEventType::FulfillmentFailed->value)->count());
        self::assertCount(2, $telegram->messages);
        $clientMessage = collect($telegram->messages)->firstWhere('recipientExternalId', 'client-fulfillment-chat');
        $financeMessage = collect($telegram->messages)->firstWhere('recipientExternalId', 'finance-fulfillment-chat');
        self::assertNotNull($clientMessage);
        self::assertNotNull($financeMessage);
        self::assertStringContainsString('Повторно оплачивать не нужно', $clientMessage->body);
        self::assertStringContainsString('Не удалось выдать доступ автоматически', $financeMessage->body);
        self::assertStringNotContainsString('internal-provider-secret', $clientMessage->body);
        self::assertStringNotContainsString('internal-provider-secret', $financeMessage->body);
        self::assertSame(1, $admin->fresh()->notifications()->count());
    }

    private function materializeAndDeliver(ScenarioEvent $event): void
    {
        app(MaterializeScenarioEvent::class)->handle($event->getKey());

        foreach (ScenarioAction::query()->where('scenario_event_id', $event->getKey())->get() as $action) {
            $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
            $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);
            app(ExecuteScenarioAction::class)->handle($action->getKey());
        }
    }

    private function paymentFixture(): array
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create([
            'name' => 'Сеанс восстановления',
            'price_minor' => 3500,
            'price_currency' => 'USD',
        ]);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        $obligation = new FinancialObligation;
        $obligation->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'booking_id' => $booking->getKey(),
            'service_id' => $service->getKey(),
            'amount_minor' => 3500,
            'currency' => 'USD',
            'base_amount_minor' => 3500,
            'base_currency' => 'USD',
            'display_amount_minor' => 3500,
            'display_currency' => 'USD',
            'payment_amount_minor' => 3500,
            'payment_currency' => 'USD',
            'settlement_amount_minor' => 3500,
            'settlement_currency' => 'USD',
            'price_snapshot' => ['amount_minor' => 3500],
            'conversion_snapshots' => [
                'base' => $this->valuationSnapshot(3500),
                'display' => $this->valuationSnapshot(3500),
            ],
            'creation_key' => 'scenario-notification-'.$organization->getKey(),
        ])->save();
        $ledgerEntry = new FinancialLedgerEntry;
        $ledgerEntry->forceFill([
            'organization_id' => $organization->getKey(),
            'obligation_id' => $obligation->getKey(),
            'entry_type' => 'manual_payment',
            'source' => 'crm',
            'amount_minor' => 3500,
            'currency' => 'USD',
            'payment_amount_minor' => 3500,
            'payment_currency' => 'USD',
            'base_amount_minor' => 3500,
            'base_currency' => 'USD',
            'display_amount_minor' => 3500,
            'display_currency' => 'USD',
            'settlement_amount_minor' => 3500,
            'settlement_currency' => 'USD',
            'payment_method' => 'cash',
            'occurred_at' => now(),
            'idempotency_key' => 'scenario-notification-ledger-'.$organization->getKey(),
            'created_at' => now(),
        ])->save();

        return [$organization, $client, $obligation->refresh(), $ledgerEntry->refresh()];
    }

    private function valuationSnapshot(int $amountMinor): array
    {
        return [
            'source_amount_minor' => (string) $amountMinor,
            'source_currency' => 'USD',
            'target_amount_minor' => (string) $amountMinor,
            'target_currency' => 'USD',
            'rate' => '1',
            'rate_id' => null,
            'rate_version' => null,
            'effective_at' => null,
            'rounding_mode' => 'half_up',
            'source_scale' => 2,
            'target_scale' => 2,
        ];
    }
}
