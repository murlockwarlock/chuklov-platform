<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentGatewayReconciliation\Pages\ListPaymentGatewayReconciliation;
use App\Models\User;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PaymentGatewayReconciliationCrmTest extends TestCase
{
    use RefreshDatabase;

    public function test_unmatched_provider_events_are_visible_without_exposing_provider_identifiers(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $admin = User::factory()->forOrganization($organization)->create();
        $event = $this->event($organization, 'refund', 'lava-refund-1', 'lava:refund:lava-refund-1');
        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $otherEvent = $this->event($otherOrganization, 'chargeback', 'lava-chargeback-2', 'lava:chargeback:lava-chargeback-2');
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)->test(ListPaymentGatewayReconciliation::class);

        $component
            ->assertSuccessful()
            ->assertTableColumnExists('event_type')
            ->assertTableColumnExists('client')
            ->assertTableColumnExists('product')
            ->assertTableColumnExists('amount')
            ->assertTableColumnExists('currency')
            ->assertTableColumnExists('reconciliation_reason')
            ->assertTableColumnExists('status')
            ->assertTableColumnStateSet('event_type', 'Возврат', $event)
            ->assertTableColumnStateSet('client', 'Не сопоставлен', $event)
            ->assertTableColumnStateSet('product', 'Не сопоставлен', $event)
            ->assertTableColumnStateSet('amount', '25.00 RUB', $event)
            ->assertTableColumnStateSet('currency', 'Российский рубль (RUB)', $event)
            ->assertTableColumnStateSet('status', 'Требует сверки', $event)
            ->assertCanSeeTableRecords([$event])
            ->assertCanNotSeeTableRecords([$otherEvent]);

        $html = $component->html();
        self::assertStringContainsString('Требует сверки', $html);
        self::assertStringContainsString('Возврат', $html);
        self::assertStringNotContainsString('lava-refund-1', $html);
        self::assertStringNotContainsString('lava:refund:lava-refund-1', $html);
    }

    private function event(Organization $organization, string $type, string $providerEventId, string $providerEventKey): PaymentGatewayEvent
    {
        $event = new PaymentGatewayEvent;
        $event->forceFill([
            'organization_id' => $organization->getKey(),
            'gateway_transaction_id' => null,
            'gateway' => 'lava',
            'event_type' => $type,
            'provider_event_id' => $providerEventId,
            'provider_event_key' => $providerEventKey,
            'provider_reference' => 'lava-contract-secret',
            'verification_status' => 'verified',
            'processing_status' => 'reconciliation_required',
            'attempt_count' => 0,
            'amount_minor' => 2500,
            'currency' => 'RUB',
            'payload_hash' => hash('sha256', $providerEventKey),
            'payload' => ['event' => $type],
            'reconciliation_reason' => 'manual_reconciliation_required',
            'created_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return $event->refresh();
    }

    private function resolveFilamentContext(User $user, Organization $organization): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }
}
