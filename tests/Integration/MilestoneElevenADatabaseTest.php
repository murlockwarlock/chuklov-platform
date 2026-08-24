<?php

namespace Tests\Integration;

use App\Modules\Feedback\Application\FeedbackRequestFingerprint;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MilestoneElevenADatabaseTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_relationship_constraints_reject_cross_organization_and_self_referral_rows(): void
    {
        $this->requirePostgres();
        [$organization, $referrer, $referred] = $this->clientFixture();
        $otherOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($otherOrganization)->create();

        try {
            DB::table('referral_relationships')->insert($this->relationshipRow(
                $organization,
                $referrer,
                $foreignClient,
            ));
            self::fail('A cross-organization relationship must be rejected.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('referral_relationships')->insert($this->relationshipRow(
            $organization,
            $referred,
            $referred,
        ));
    }

    public function test_postgresql_composite_evidence_constraints_reject_mismatched_obligation_client_and_ledger(): void
    {
        $this->requirePostgres();
        [$organization, $referrer, $referred] = $this->clientFixture();
        $otherClient = Client::factory()->forOrganization($organization)->create();
        $relationship = new ReferralRelationship;
        $relationship->forceFill([
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $referrer->getKey(),
            'referred_client_id' => $referred->getKey(),
            'establishment_method' => 'automatic_referral_link',
            'registered_at' => now(),
        ])->save();
        $relationship = ReferralRelationship::query()->findOrFail($relationship->getKey());
        [$obligation, $ledger] = $this->financeFixture($organization, $referred);
        [$otherObligation, $otherLedger] = $this->financeFixture($organization, $otherClient, 'm11a-other');
        $event = new IntegrationEvent;
        $event->forceFill([
            'organization_id' => $organization->getKey(),
            'event_type' => 'finance.obligation.settled',
            'aggregate_type' => 'financial_obligation',
            'aggregate_id' => $obligation->getKey(),
            'idempotency_key' => 'm11a-db-evidence-event',
            'payload' => ['obligation_id' => $obligation->getKey()],
            'status' => 'pending',
            'attempt_count' => 0,
            'occurred_at' => now(),
            'available_at' => now(),
        ])->save();
        $event = IntegrationEvent::query()->findOrFail($event->getKey());

        try {
            DB::table('referral_commercial_evidence')->insert($this->evidenceRow(
                $organization,
                $event,
                $relationship,
                $otherClient,
                $obligation,
                $ledger,
                'm11a-mismatched-client',
            ));
            self::fail('Relationship/client mismatch must be rejected.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('referral_commercial_evidence')->insert($this->evidenceRow(
                $organization,
                $event,
                $relationship,
                $referred,
                $otherObligation,
                $otherLedger,
                'm11a-mismatched-obligation',
            ));
            self::fail('Obligation/client mismatch must be rejected.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('referral_commercial_evidence')->insert($this->evidenceRow(
            $organization,
            $event,
            $relationship,
            $referred,
            $obligation,
            $otherLedger,
            'm11a-mismatched-ledger',
        ));
    }

    public function test_postgresql_feedback_key_uniqueness_and_score_checks_are_database_authority(): void
    {
        $this->requirePostgres();
        [$organization, , $client] = $this->clientFixture();
        $fingerprint = app(FeedbackRequestFingerprint::class)->handle([
            'client_id' => $client->getKey(),
            'score' => 8,
            'internal_feedback' => null,
            'source' => 'portal',
        ]);
        DB::table('feedback_submissions')->insert([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'score' => 8,
            'source' => 'portal',
            'idempotency_key' => 'database-test-feedback',
            'request_hash' => $fingerprint,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('feedback_submissions')->insert([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'score' => 9,
                'source' => 'portal',
                'idempotency_key' => 'database-test-feedback',
                'request_hash' => $fingerprint,
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            self::fail('The idempotency key must be unique per client and organization.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('feedback_submissions')->insert([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'score' => 11,
            'source' => 'portal',
            'idempotency_key' => 'database-test-invalid-score',
            'request_hash' => $fingerprint,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_postgresql_consumed_pre_auth_attribution_preserves_client_ownership_on_delete(): void
    {
        $this->requirePostgres();
        [$organization, , $client] = $this->clientFixture();
        DB::table('pre_auth_attributions')->insert([
            'organization_id' => $organization->getKey(),
            'session_hash' => hash('sha256', 'm11a-delete-policy'),
            'source_type' => 'source',
            'source' => 'Campaign',
            'capture_channel' => 'portal',
            'captured_at' => now(),
            'expires_at' => now()->addDay(),
            'consumed_at' => now(),
            'consumed_client_id' => $client->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $client->delete();
    }

    /** @return array{Organization, Client, Client} */
    private function clientFixture(): array
    {
        $organization = Organization::factory()->create();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $referred = Client::factory()->forOrganization($organization)->create();

        return [$organization, $referrer, $referred];
    }

    /** @return array<string, mixed> */
    private function relationshipRow(Organization $organization, Client $referrer, Client $referred): array
    {
        return [
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $referrer->getKey(),
            'referred_client_id' => $referred->getKey(),
            'establishment_method' => 'automatic_referral_link',
            'registered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array{FinancialObligation, FinancialLedgerEntry} */
    private function financeFixture(Organization $organization, Client $client, string $suffix = 'main'): array
    {
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => 10000,
            'price_currency' => 'USD',
        ]);
        $booking = Booking::factory()->forClient($client)->forService($service)->create();
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
        $obligation = new FinancialObligation;
        $obligation->forceFill([
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
            'creation_key' => 'm11a-'.$suffix.'-'.$client->getKey(),
        ]);
        $obligation->save();
        $ledger = new FinancialLedgerEntry;
        $ledger->forceFill([
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
            'idempotency_key' => 'm11a-'.$suffix.'-entry-'.$client->getKey(),
            'created_at' => now(),
        ]);
        $ledger->save();

        return [$obligation, $ledger];
    }

    /** @return array<string, mixed> */
    private function evidenceRow(
        Organization $organization,
        IntegrationEvent $event,
        ReferralRelationship $relationship,
        Client $referred,
        FinancialObligation $obligation,
        FinancialLedgerEntry $ledger,
        string $suffix,
    ): array {
        return [
            'organization_id' => $organization->getKey(),
            'integration_event_id' => $event->getKey(),
            'referral_relationship_id' => $relationship->getKey(),
            'referred_client_id' => $referred->getKey(),
            'financial_obligation_id' => $obligation->getKey(),
            'financial_ledger_entry_id' => $ledger->getKey(),
            'evidence_type' => 'finance_obligation_settled',
            'observation_source' => 'finance',
            'observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('M11A database assertions require PostgreSQL composite foreign keys and CHECK constraints.');
        }
    }
}
