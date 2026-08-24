<?php

namespace Tests\Integration;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Models\ClientReferralIdentity;
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

    public function test_postgresql_m11a_constraints_reject_cross_organization_and_self_referral_rows(): void
    {
        $this->requirePostgres();
        [$organization, $client] = $this->clientFixture();
        $otherOrganization = Organization::factory()->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $identity = ClientReferralIdentity::query()->create([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'public_code' => 'database-test-referral-code',
        ]);

        try {
            DB::table('referral_relationships')->insert([
                'organization_id' => $otherOrganization->getKey(),
                'referral_identity_id' => $identity->getKey(),
                'referrer_client_id' => $otherClient->getKey(),
                'referred_client_id' => $otherClient->getKey(),
                'attribution_source_type' => 'referral',
                'registered_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            self::fail('A cross-organization referral relationship must be rejected.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('referral_relationships')->insert([
            'organization_id' => $organization->getKey(),
            'referral_identity_id' => $identity->getKey(),
            'referrer_client_id' => $client->getKey(),
            'referred_client_id' => $client->getKey(),
            'attribution_source_type' => 'referral',
            'registered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_postgresql_m11a_uniqueness_and_score_checks_are_database_authority(): void
    {
        $this->requirePostgres();
        [$organization, $client] = $this->clientFixture();
        DB::table('feedback_configurations')->insert([
            'organization_id' => $organization->getKey(),
            'enabled' => true,
            'positive_threshold' => 8,
            'low_score_feedback_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('feedback_submissions')->insert([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'score' => 8,
            'source' => 'portal',
            'idempotency_key' => 'database-test-feedback',
            'request_hash' => hash('sha256', 'database-test-feedback'),
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('feedback_submissions')->insert([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'score' => 9,
            'source' => 'portal',
            'idempotency_key' => 'database-test-feedback',
            'request_hash' => hash('sha256', 'different-request'),
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_postgresql_m11a_score_check_rejects_values_outside_nps_bounds(): void
    {
        $this->requirePostgres();
        [$organization, $client] = $this->clientFixture();

        $this->expectException(QueryException::class);
        DB::table('feedback_submissions')->insert([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'score' => 11,
            'source' => 'portal',
            'idempotency_key' => 'database-test-invalid-score',
            'request_hash' => hash('sha256', 'database-test-invalid-score'),
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{Organization, Client} */
    private function clientFixture(): array
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();

        return [$organization, $client];
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('M11A database constraints require PostgreSQL composite foreign keys and CHECK constraints.');
        }
    }
}
