<?php

namespace Tests\Integration;

use App\Modules\Attribution\Application\CapturePreAuthAttribution;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\EnsureReferralIdentity;
use App\Modules\Referrals\Application\FinalizeClientAcquisition;
use App\Modules\Referrals\Application\ObserveReferredPaidConversion;
use App\Modules\Referrals\Domain\Models\ReferralConversionObservation;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Referrals\Domain\ValueObjects\PaidConversionEvidence;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MilestoneElevenAConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_two_processes_claim_one_referred_client_once(): void
    {
        $this->requirePostgres();
        [$organization, $referrerA, $referrerB, $referred] = $this->referralFixture();
        $identityA = app(EnsureReferralIdentity::class)->handle($referrerA);
        $identityB = app(EnsureReferralIdentity::class)->handle($referrerB);
        app(CapturePreAuthAttribution::class)->handle('m11a-session-a', ['referral_code' => $identityA->public_code]);
        app(CapturePreAuthAttribution::class)->handle('m11a-session-b', ['referral_code' => $identityB->public_code]);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::claimInProcess($organization->getKey(), $referred->getKey(), 'm11a-session-a'),
            static fn (): string => self::claimInProcess($organization->getKey(), $referred->getKey(), 'm11a-session-b'),
        ]);

        self::assertNotContains('error', $results);
        self::assertSame(1, ReferralRelationship::query()->where('referred_client_id', $referred->getKey())->count());
        self::assertCount(2, $results);
    }

    public function test_two_processes_record_one_paid_conversion_observation(): void
    {
        $this->requirePostgres();
        [$organization, $referrer, , $referred] = $this->referralFixture();
        $identity = app(EnsureReferralIdentity::class)->handle($referrer);
        app(CapturePreAuthAttribution::class)->handle('m11a-session-conversion', ['referral_code' => $identity->public_code]);
        app(FinalizeClientAcquisition::class)->handle($referred, 'm11a-session-conversion', true);
        $relationship = ReferralRelationship::query()->where('referred_client_id', $referred->getKey())->firstOrFail();
        [$obligation, $entry] = $this->financeFixture($organization, $referred);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::observeInProcess($obligation->getKey(), $entry->getKey()),
            static fn (): string => self::observeInProcess($obligation->getKey(), $entry->getKey()),
        ]);

        self::assertNotContains('error', $results);
        self::assertSame(1, ReferralConversionObservation::query()
            ->where('referral_relationship_id', $relationship->getKey())
            ->where('financial_obligation_id', $obligation->getKey())
            ->count());
        self::assertSame(1, count(array_unique($results)));
    }

    /** @return array{Organization, Client, Client, Client} */
    private function referralFixture(): array
    {
        $organization = Organization::factory()->create();
        $referrerA = Client::factory()->forOrganization($organization)->create();
        $referrerB = Client::factory()->forOrganization($organization)->create();
        $referred = Client::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $referrerA, $referrerB, $referred];
    }

    /** @return array{FinancialObligation, FinancialLedgerEntry} */
    private function financeFixture(Organization $organization, Client $client): array
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
            'creation_key' => 'm11a-conversion-'.$client->getKey(),
        ])->save();
        $entry = new FinancialLedgerEntry;
        $entry->forceFill([
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
            'idempotency_key' => 'm11a-conversion-entry-'.$client->getKey(),
            'created_at' => now(),
        ])->save();

        return [$obligation, $entry];
    }

    private static function claimInProcess(int $organizationId, int $clientId, string $sessionId): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            app(FinalizeClientAcquisition::class)->handle(
                Client::query()->where('organization_id', $organizationId)->findOrFail($clientId),
                $sessionId,
                true,
            );

            return 'claimed';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function observeInProcess(int $obligationId, int $entryId): string
    {
        try {
            $obligation = FinancialObligation::query()->findOrFail($obligationId);
            $entry = FinancialLedgerEntry::query()->findOrFail($entryId);
            $observation = app(ObserveReferredPaidConversion::class)->handle(
                new PaidConversionEvidence(
                    organizationId: (int) $obligation->organization_id,
                    clientId: (int) $obligation->client_id,
                    obligationId: (int) $obligation->getKey(),
                    ledgerEntryId: (int) $entry->getKey(),
                    financeStatus: 'settled',
                    authoritativeSettled: true,
                ),
            );

            return 'observation:'.$observation?->getKey();
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('M11A concurrency tests require PostgreSQL row locks and unique indexes.');
        }
    }
}
