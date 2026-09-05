<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Attribution\Application\CapturePreAuthAttribution;
use App\Modules\Attribution\Application\ManageAttributionSourceDetail;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Attribution\Domain\Models\PreAuthAttribution;
use App\Modules\Identity\Application\RegisterClientAcquisition;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\FinalizeClientAcquisition;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrganicRecommendationDetailPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_persists_protected_detail_across_pre_auth_finalization(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create(['lead_source' => null]);
        $actor = User::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        $sessionId = 'organic-detail-postgres-session';
        app(CapturePreAuthAttribution::class)->handle(
            $sessionId,
            ['source' => 'friend'],
            sourceDetail: 'Дарья, @daria, +7 700 000 00 00',
        );

        $columns = DB::select(
            "SELECT table_name, column_name FROM information_schema.columns WHERE table_name IN ('pre_auth_attributions', 'client_attributions') AND column_name IN ('encrypted_source_detail', 'source_detail_key_version') ORDER BY table_name, column_name"
        );
        self::assertSame([
            'client_attributions:encrypted_source_detail',
            'client_attributions:source_detail_key_version',
            'pre_auth_attributions:encrypted_source_detail',
            'pre_auth_attributions:source_detail_key_version',
        ], array_map(static fn (object $column): string => $column->table_name.':'.$column->column_name, $columns));

        $preAuth = PreAuthAttribution::query()->sole();
        self::assertNotSame('Дарья, @daria, +7 700 000 00 00', $preAuth->encrypted_source_detail);
        self::assertSame(1, (int) $preAuth->source_detail_key_version);

        app(RegisterClientAcquisition::class)->handle($organization, $client, $sessionId);
        app(FinalizeClientAcquisition::class)->handle($client, $sessionId);

        $attribution = ClientAttribution::query()->sole();
        self::assertSame($preAuth->encrypted_source_detail, $attribution->encrypted_source_detail);
        self::assertSame('Дарья, @daria, +7 700 000 00 00', app(ManageAttributionSourceDetail::class)->read($actor, $client));
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Protected attribution persistence requires PostgreSQL verification.');
        }
    }
}
