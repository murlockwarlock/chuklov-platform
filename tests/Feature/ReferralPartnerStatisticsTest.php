<?php

namespace Tests\Feature;

use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\CreateReferralCampaignLink;
use App\Modules\Referrals\Application\GetReferralPartnerOverview;
use App\Modules\Referrals\Application\RecordReferralLinkVisit;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Models\ReferralCommercialEvidence;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReferralPartnerStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_distinguishes_link_visits_registrations_and_paid_clients(): void
    {
        $organization = $this->organization();
        $partner = Client::factory()->forOrganization($organization)->create(['full_name' => 'Партнёр']);
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $instagram = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Instagram — профиль',
            channel: ReferralCampaignChannel::Instagram,
        );
        $telegram = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Telegram — канал',
            channel: ReferralCampaignChannel::Telegram,
        );
        app(RecordReferralLinkVisit::class)->handle($instagram->public_token, 'visit-1');
        app(RecordReferralLinkVisit::class)->handle($instagram->public_token, 'visit-2');
        app(RecordReferralLinkVisit::class)->handle($telegram->public_token, 'visit-3');

        $paidClient = Client::factory()->forOrganization($organization)->create(['full_name' => 'Оплативший']);
        $registeredClient = Client::factory()->forOrganization($organization)->create(['full_name' => 'Зарегистрированный']);
        $firstRelationship = $this->relationship($organization, $partner, $paidClient, $instagram->getKey());
        $this->relationship($organization, $partner, $registeredClient, $telegram->getKey());
        $this->commercialEvidence($organization, $firstRelationship, $paidClient);

        $overview = app(GetReferralPartnerOverview::class)->handle($partner);

        self::assertSame(3, $overview['stats']['visits']);
        self::assertSame(2, $overview['stats']['registrations']);
        self::assertSame(1, $overview['stats']['paidClients']);
        self::assertSame(66.7, $overview['stats']['visitToRegistrationRate']);
        self::assertSame(50.0, $overview['stats']['registrationToPaidClientRate']);
        self::assertSame(2, $overview['links'][1]['visits']);
        self::assertSame(1, $overview['links'][1]['registrations']);
        self::assertSame(1, $overview['links'][1]['paidClients']);
        self::assertSame(1, $overview['links'][2]['visits']);
        self::assertSame(1, $overview['links'][2]['registrations']);
        self::assertSame(0, $overview['links'][2]['paidClients']);
        self::assertSame('Instagram', $overview['links'][1]['channel']);
        self::assertSame('Telegram', $overview['links'][2]['channel']);
    }

    public function test_overview_marks_only_authoritative_commercial_evidence_as_paid(): void
    {
        $organization = $this->organization();
        $partner = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $link = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Сайт — страница',
            channel: ReferralCampaignChannel::Website,
        );
        $bookedClient = Client::factory()->forOrganization($organization)->create();
        $relationship = $this->relationship($organization, $partner, $bookedClient, $link->getKey());
        $bookedClient->forceFill(['lead_source' => 'organic'])->save();

        $overview = app(GetReferralPartnerOverview::class)->handle($partner);

        self::assertSame(0, $overview['stats']['paidClients']);
        self::assertFalse($overview['registrations'][0]['paidClient']);

        $this->commercialEvidence($organization, $relationship, $bookedClient);

        $overview = app(GetReferralPartnerOverview::class)->handle($partner);

        self::assertSame(1, $overview['stats']['paidClients']);
        self::assertTrue($overview['registrations'][0]['paidClient']);
    }

    public function test_visit_conversion_uses_only_campaign_registrations_while_total_registrations_stays_overall(): void
    {
        $organization = $this->organization();
        $partner = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $link = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Campaign',
            channel: ReferralCampaignChannel::Instagram,
        );
        foreach (['campaign-1', 'campaign-2', 'campaign-3', 'campaign-4'] as $session) {
            app(RecordReferralLinkVisit::class)->handle($link->public_token, $session);
        }

        $this->relationship($organization, $partner, Client::factory()->forOrganization($organization)->create(), $link->getKey());
        $this->relationship($organization, $partner, Client::factory()->forOrganization($organization)->create(), $link->getKey());
        $this->relationshipWithoutCampaign($organization, $partner, Client::factory()->forOrganization($organization)->create(), 'manual_crm');
        $this->relationshipWithoutCampaign($organization, $partner, Client::factory()->forOrganization($organization)->create(), 'automatic_referral_link');

        $overview = app(GetReferralPartnerOverview::class)->handle($partner);

        self::assertSame(4, $overview['stats']['registrations']);
        self::assertSame(50.0, $overview['stats']['visitToRegistrationRate']);
        self::assertSame(4, $overview['referredClientsCount']);
    }

    public function test_registration_labels_preserve_manual_and_legacy_automatic_provenance(): void
    {
        $organization = $this->organization();
        $partner = Client::factory()->forOrganization($organization)->create();
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $campaign = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Telegram campaign',
            channel: ReferralCampaignChannel::Telegram,
        );
        $manual = Client::factory()->forOrganization($organization)->create(['full_name' => 'Manual']);
        $legacy = Client::factory()->forOrganization($organization)->create(['full_name' => 'Legacy']);
        $campaignClient = Client::factory()->forOrganization($organization)->create(['full_name' => 'Campaign']);
        $this->relationshipWithoutCampaign($organization, $partner, $manual, 'manual_crm');
        $this->relationshipWithoutCampaign($organization, $partner, $legacy, 'automatic_referral_link');
        $this->relationship($organization, $partner, $campaignClient, $campaign->getKey());

        $overview = app(GetReferralPartnerOverview::class)->handle($partner);
        $registrations = collect($overview['registrations'])->keyBy('name');

        self::assertSame('Назначено в CRM', $registrations['Manual']['linkName']);
        self::assertSame('CRM', $registrations['Manual']['channel']);
        self::assertSame('Персональная ссылка', $registrations['Legacy']['linkName']);
        self::assertSame('Ссылка', $registrations['Legacy']['channel']);
        self::assertSame('Telegram campaign', $registrations['Campaign']['linkName']);
        self::assertSame('Telegram', $registrations['Campaign']['channel']);
    }

    private function relationship(Organization $organization, Client $partner, Client $client, int $linkId): ReferralRelationship
    {
        return ReferralRelationship::forceCreate([
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $partner->getKey(),
            'referred_client_id' => $client->getKey(),
            'establishment_method' => 'automatic_referral_link',
            'referral_campaign_link_id' => $linkId,
            'registered_at' => now(),
        ]);
    }

    private function relationshipWithoutCampaign(Organization $organization, Client $partner, Client $client, string $method): ReferralRelationship
    {
        return ReferralRelationship::forceCreate([
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $partner->getKey(),
            'referred_client_id' => $client->getKey(),
            'establishment_method' => $method,
            'registered_at' => now(),
        ]);
    }

    private function commercialEvidence(Organization $organization, ReferralRelationship $relationship, Client $client): void
    {
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => 10000,
            'price_currency' => 'USD',
        ]);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        $snapshot = [
            'source_amount_minor' => '10000',
            'source_currency' => 'USD',
            'target_amount_minor' => '10000',
            'target_currency' => 'USD',
            'rate' => '1',
            'rate_id' => null,
            'rate_version' => null,
            'effective_at' => null,
            'rounding_mode' => 'half_up',
            'source_scale' => 2,
            'target_scale' => 2,
        ];
        $obligation = FinancialObligation::forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'booking_id' => $booking->getKey(),
            'service_id' => $service->getKey(),
            'amount_minor' => 10000,
            'currency' => 'USD',
            'base_amount_minor' => 10000,
            'base_currency' => 'USD',
            'display_amount_minor' => 10000,
            'display_currency' => 'USD',
            'payment_amount_minor' => 10000,
            'payment_currency' => 'USD',
            'settlement_amount_minor' => 10000,
            'settlement_currency' => 'USD',
            'price_snapshot' => ['amount_minor' => 10000],
            'conversion_snapshots' => ['base' => $snapshot, 'display' => $snapshot],
            'creation_key' => 'partner-stats-'.$client->getKey(),
        ]);
        $entry = FinancialLedgerEntry::forceCreate([
            'organization_id' => $organization->getKey(),
            'obligation_id' => $obligation->getKey(),
            'entry_type' => 'manual_payment',
            'source' => 'crm',
            'amount_minor' => 10000,
            'currency' => 'USD',
            'payment_amount_minor' => 10000,
            'payment_currency' => 'USD',
            'base_amount_minor' => 10000,
            'base_currency' => 'USD',
            'display_amount_minor' => 10000,
            'display_currency' => 'USD',
            'settlement_amount_minor' => 10000,
            'settlement_currency' => 'USD',
            'payment_method' => 'cash',
            'occurred_at' => now(),
            'idempotency_key' => 'partner-stats-entry-'.$client->getKey(),
            'created_at' => now(),
        ]);
        $event = IntegrationEvent::forceCreate([
            'organization_id' => $organization->getKey(),
            'event_type' => 'finance.obligation.settled',
            'aggregate_type' => 'financial_obligation',
            'aggregate_id' => $obligation->getKey(),
            'idempotency_key' => 'partner-stats-event-'.$client->getKey(),
            'payload' => [],
            'status' => 'processed',
            'attempt_count' => 1,
            'occurred_at' => now(),
            'available_at' => now(),
            'processed_at' => now(),
        ]);
        ReferralCommercialEvidence::forceCreate([
            'organization_id' => $organization->getKey(),
            'integration_event_id' => $event->getKey(),
            'referral_relationship_id' => $relationship->getKey(),
            'referred_client_id' => $client->getKey(),
            'financial_obligation_id' => $obligation->getKey(),
            'financial_ledger_entry_id' => $entry->getKey(),
            'evidence_type' => 'finance_obligation_settled',
            'observation_source' => 'finance',
            'observed_at' => now(),
        ]);
    }

    private function organization(): Organization
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        app(OrganizationContext::class)->set($organization);

        return $organization;
    }
}
