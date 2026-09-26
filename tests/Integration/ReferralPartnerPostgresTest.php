<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Attribution\Application\CapturePreAuthAttribution;
use App\Modules\Identity\Application\RegisterClientAcquisition;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\CreateReferralCampaignLink;
use App\Modules\Referrals\Application\DeactivateReferralCampaignLink;
use App\Modules\Referrals\Application\FinalizeClientAcquisition;
use App\Modules\Referrals\Application\GetReferralPartnerOverview;
use App\Modules\Referrals\Application\RecordReferralLinkVisit;
use App\Modules\Referrals\Application\ResolveReferralCode;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReferralPartnerPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_partner_links_preserve_history_and_tenant_provenance_constraints(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $partner = Client::factory()->forOrganization($organization)->create(['full_name' => 'Partner A']);
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        app(OrganizationContext::class)->set($organization);
        $profile = app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $link = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Instagram profile',
            channel: ReferralCampaignChannel::Instagram,
        );
        $referred = Client::factory()->forOrganization($organization)->create(['full_name' => 'Referred A']);
        ReferralRelationship::forceCreate([
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $partner->getKey(),
            'referred_client_id' => $referred->getKey(),
            'establishment_method' => 'automatic_referral_link',
            'referral_campaign_link_id' => $link->getKey(),
            'registered_at' => now(),
        ]);

        $admin = User::factory()->forOrganization($organization)->create();
        app(DeactivateReferralCampaignLink::class)->handle($link, $admin);

        $resolved = app(ResolveReferralCode::class)->handle($organization->getKey(), $link->public_token);
        self::assertInstanceOf(ReferralCampaignLink::class, $resolved);
        self::assertFalse($resolved->isActive());
        self::assertNull(app(RecordReferralLinkVisit::class)->handle($link->public_token, 'disabled-history-session'));

        $overview = app(GetReferralPartnerOverview::class)->handle($partner);
        self::assertSame($link->getKey(), ReferralRelationship::query()->sole()->referral_campaign_link_id);
        self::assertSame('Instagram profile', $overview['registrations'][0]['linkName']);
        self::assertSame('Instagram', $overview['registrations'][0]['channel']);
        self::assertArrayNotHasKey('isActive', $overview['links'][1]);
        self::assertSame($profile->getKey(), $link->partner_profile_id);

        $foreignOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $foreignPartner = Client::factory()->forOrganization($foreignOrganization)->create();
        app(OrganizationContext::class)->set($foreignOrganization);
        $foreignLink = app(ActivateReferralPartner::class)->handle($foreignPartner, 'portal')->activeCampaignLinks()->firstOrFail();
        app(OrganizationContext::class)->set($organization);

        $this->assertQueryFails(static fn (): mixed => DB::table('referral_relationships')->insert([
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $partner->getKey(),
            'referred_client_id' => Client::factory()->forOrganization($organization)->create()->getKey(),
            'establishment_method' => 'automatic_referral_link',
            'referral_campaign_link_id' => $foreignLink->getKey(),
            'registered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_postgresql_partner_indexes_cover_token_visit_and_organization_queries(): void
    {
        $this->requirePostgres();
        $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename IN ('referral_partner_profiles', 'referral_campaign_links', 'referral_link_visits', 'referral_relationships')"))
            ->pluck('indexname')
            ->all();

        self::assertContains('ref_partner_profile_org_client_unique', $indexes);
        self::assertContains('ref_campaign_link_org_token_unique', $indexes);
        self::assertContains('ref_campaign_link_org_profile_active_index', $indexes);
        self::assertContains('ref_link_visit_org_link_occurred_index', $indexes);
        self::assertContains('ref_rel_org_campaign_registered_index', $indexes);
    }

    public function test_postgresql_repair_reactivates_legacy_default_without_changing_identity_or_history(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $partner = Client::factory()->forOrganization($organization)->create(['full_name' => 'Legacy Partner']);
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        app(OrganizationContext::class)->set($organization);
        $profile = app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $link = $profile->campaignLinks()->where('is_default', true)->firstOrFail();
        $shareUrlBeforeRepair = collect(app(GetReferralPartnerOverview::class)->handle($partner)['links'])
            ->firstWhere('name', 'Личные рекомендации')['shareUrl'];
        app(RecordReferralLinkVisit::class)->handle($link->public_token, 'legacy-history-session');

        $historicalClient = Client::factory()->forOrganization($organization)->create(['full_name' => 'Historical Referred']);
        ReferralRelationship::forceCreate([
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $partner->getKey(),
            'referred_client_id' => $historicalClient->getKey(),
            'establishment_method' => 'automatic_referral_link',
            'referral_campaign_link_id' => $link->getKey(),
            'registered_at' => now(),
        ]);

        $id = $link->getKey();
        $token = $link->public_token;
        $link->forceFill([
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_by_user_id' => $admin->getKey(),
        ])->save();

        app(ActivateReferralPartner::class)->handle($partner, 'crm', $admin);
        self::assertSame(1, ReferralCampaignLink::query()->where('partner_profile_id', $profile->getKey())->where('is_default', true)->count());
        self::assertSame($id, ReferralCampaignLink::query()->where('partner_profile_id', $profile->getKey())->where('is_default', true)->value('id'));

        $migrationPaths = glob(database_path('migrations/*reactivate_disabled_referral_campaign_links.php')) ?: [];
        self::assertCount(1, $migrationPaths);
        $migration = require $migrationPaths[0];
        $migration->up();

        $repaired = $link->fresh();
        self::assertTrue($repaired->is_active);
        self::assertNull($repaired->disabled_at);
        self::assertNull($repaired->disabled_by_user_id);
        self::assertSame($id, $repaired->getKey());
        self::assertSame($token, $repaired->public_token);
        self::assertSame(
            $shareUrlBeforeRepair,
            collect(app(GetReferralPartnerOverview::class)->handle($partner)['links'])
                ->firstWhere('name', 'Личные рекомендации')['shareUrl'],
        );
        self::assertSame($id, app(ResolveReferralCode::class)->handle($organization->getKey(), $token)?->getKey());
        self::assertSame(1, DB::table('referral_link_visits')->where('campaign_link_id', $id)->count());
        self::assertSame(1, ReferralRelationship::query()->where('referral_campaign_link_id', $id)->count());

        app(RecordReferralLinkVisit::class)->handle($token, 'repaired-history-session');
        self::assertSame(2, DB::table('referral_link_visits')->where('campaign_link_id', $id)->count());

        $sessionId = 'repaired-attribution-session';
        app(CapturePreAuthAttribution::class)->handle(
            sessionId: $sessionId,
            input: ['referral_code' => $token],
            captureChannel: 'portal',
            captureContext: 'referral_route',
        );
        $attributedClient = Client::factory()->forOrganization($organization)->create(['lead_source' => null]);
        app(RegisterClientAcquisition::class)->handle($organization, $attributedClient, $sessionId);
        app(FinalizeClientAcquisition::class)->handle($attributedClient, $sessionId);
        self::assertSame($id, ReferralRelationship::query()->where('referred_client_id', $attributedClient->getKey())->sole()->referral_campaign_link_id);
        self::assertDatabaseHas('referral_relationships', [
            'referred_client_id' => $historicalClient->getKey(),
            'referral_campaign_link_id' => $id,
        ]);

        app(ActivateReferralPartner::class)->handle($partner, 'crm', $admin);
        self::assertSame(1, ReferralCampaignLink::query()->where('partner_profile_id', $profile->getKey())->where('is_default', true)->count());
        self::assertSame($id, ReferralCampaignLink::query()->where('partner_profile_id', $profile->getKey())->where('is_default', true)->value('id'));

        $migration->up();
        $repairedAgain = $repaired->fresh();
        self::assertTrue($repairedAgain->is_active);
        self::assertSame($token, $repairedAgain->public_token);
        self::assertSame(2, DB::table('referral_link_visits')->where('campaign_link_id', $id)->count());
        self::assertSame(2, ReferralRelationship::query()->where('referral_campaign_link_id', $id)->count());
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Referral partner PostgreSQL coverage requires the PostgreSQL schema and foreign keys.');
        }
    }

    private function assertQueryFails(callable $query): void
    {
        try {
            $query();
        } catch (QueryException) {
            return;
        }

        self::fail('Expected the PostgreSQL constraint to reject the query.');
    }
}
