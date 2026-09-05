<?php

namespace Tests\Integration;

use App\Modules\Attribution\Application\CapturePreAuthAttribution;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Identity\Application\RegisterClientAcquisition;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\CreateReferralCampaignLink;
use App\Modules\Referrals\Application\FinalizeClientAcquisition;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReferralPartnerConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_concurrent_activation_creates_one_profile_and_default_link(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::activateInProcess($organization->getKey(), $client->getKey()),
            static fn (): string => self::activateInProcess($organization->getKey(), $client->getKey()),
        ]);

        self::assertNotContains('error', $results, implode(', ', $results));
        self::assertCount(2, array_filter($results, static fn (string $result): bool => str_starts_with($result, 'profile:')));
        self::assertSame(1, ReferralPartnerProfile::query()->where('organization_id', $organization->getKey())->where('client_id', $client->getKey())->count());
        self::assertSame(1, ReferralCampaignLink::query()->where('organization_id', $organization->getKey())->where('partner_client_id', $client->getKey())->where('is_default', true)->count());
    }

    public function test_postgresql_concurrent_campaign_link_creation_keeps_links_distinct(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        app(ActivateReferralPartner::class)->handle($client, 'portal');

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::createLinkInProcess($organization->getKey(), $client->getKey(), 'Instagram'),
            static fn (): string => self::createLinkInProcess($organization->getKey(), $client->getKey(), 'Telegram'),
        ]);

        self::assertNotContains('error', $results, implode(', ', $results));
        self::assertCount(2, array_filter($results, static fn (string $result): bool => str_starts_with($result, 'link:')));
        self::assertSame(3, ReferralCampaignLink::query()->where('organization_id', $organization->getKey())->where('partner_client_id', $client->getKey())->count());
        self::assertSame(3, ReferralCampaignLink::query()->where('organization_id', $organization->getKey())->where('partner_client_id', $client->getKey())->pluck('public_token')->unique()->count());
    }

    public function test_postgresql_concurrent_same_campaign_registration_finalizes_once(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $partner = Client::factory()->forOrganization($organization)->create();
        $referred = Client::factory()->forOrganization($organization)->create(['lead_source' => null]);
        app(OrganizationContext::class)->set($organization);
        app(ActivateReferralPartner::class)->handle($partner, 'portal');
        $link = app(CreateReferralCampaignLink::class)->handle(
            client: $partner,
            name: 'Telegram channel',
            channel: ReferralCampaignChannel::Telegram,
        );
        $sessionId = 'parallel-campaign-registration';
        app(CapturePreAuthAttribution::class)->handle(
            sessionId: $sessionId,
            input: ['referral_code' => $link->public_token],
            captureChannel: 'portal',
            captureContext: 'referral_route',
        );
        app(RegisterClientAcquisition::class)->handle($organization, $referred, $sessionId);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::finalizeInProcess($organization->getKey(), $referred->getKey(), $sessionId),
            static fn (): string => self::finalizeInProcess($organization->getKey(), $referred->getKey(), $sessionId),
        ]);

        self::assertNotContains('error', $results, implode(', ', $results));
        self::assertSame(1, ReferralRelationship::query()->where('organization_id', $organization->getKey())->where('referred_client_id', $referred->getKey())->count());
        self::assertSame(1, ClientAttribution::query()->where('organization_id', $organization->getKey())->where('client_id', $referred->getKey())->count());
        self::assertNotNull(DB::table('client_acquisition_registrations')->where('organization_id', $organization->getKey())->where('client_id', $referred->getKey())->value('finalized_at'));
    }

    private static function activateInProcess(int $organizationId, int $clientId): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            $profile = app(ActivateReferralPartner::class)->handle(Client::query()->where('organization_id', $organizationId)->findOrFail($clientId), 'portal');

            return 'profile:'.$profile->getKey();
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function createLinkInProcess(int $organizationId, int $clientId, string $channel): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            $link = app(CreateReferralCampaignLink::class)->handle(
                client: Client::query()->where('organization_id', $organizationId)->findOrFail($clientId),
                name: $channel.' campaign',
                channel: ReferralCampaignChannel::from(strtolower($channel)),
            );

            return 'link:'.$link->getKey();
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function finalizeInProcess(int $organizationId, int $clientId, string $sessionId): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            app(FinalizeClientAcquisition::class)->handle(
                client: Client::query()->where('organization_id', $organizationId)->findOrFail($clientId),
                sessionId: $sessionId,
            );

            return 'finalized';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Referral partner concurrency coverage requires PostgreSQL row locks.');
        }
    }
}
