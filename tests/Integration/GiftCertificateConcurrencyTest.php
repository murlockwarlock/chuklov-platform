<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Commerce\Application\AppendGiftCertificateMovement;
use App\Modules\Commerce\Application\ApplyGiftCertificateToObligation;
use App\Modules\Commerce\Application\ClaimGiftCertificate;
use App\Modules\Commerce\Application\CreateCrmGiftCertificateSale;
use App\Modules\Commerce\Application\CreateGiftCertificateTransfer;
use App\Modules\Commerce\Application\GiftCertificateBalanceProjection;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Enums\GiftCertificateMovementType;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificateClaim;
use App\Modules\Commerce\Domain\Models\GiftCertificateMovement;
use App\Modules\Commerce\Domain\Models\GiftCertificateRedemption;
use App\Modules\Commerce\Domain\Models\Purchase;
use App\Modules\Commerce\Domain\Models\PurchaseFulfillment;
use App\Modules\Commerce\Domain\Models\PurchaseItem;
use App\Modules\Finance\Application\CreateFinancialObligation;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class GiftCertificateConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_concurrent_redemption_cannot_overspend_certificate_or_obligation(): void
    {
        $this->requirePostgres();
        [$organization, $client, $certificate, $obligation] = $this->fixture();

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::redeemInProcess($organization->getKey(), $client->getKey(), $certificate->getKey(), $obligation->getKey(), 'gift-race-one'),
            static fn (): string => self::redeemInProcess($organization->getKey(), $client->getKey(), $certificate->getKey(), $obligation->getKey(), 'gift-race-two'),
        ]);

        self::assertNotContains('error', $results);
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'entry:'))));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'validation')));
        self::assertSame(1, FinancialLedgerEntry::query()->where('entry_type', 'gift_certificate_redemption')->count());
        self::assertSame(4000, app(GiftCertificateBalanceProjection::class)->balance($certificate->fresh())->minorUnits());
        self::assertSame(4000, app(ReconcileFinancialObligation::class)->handle($organization->getKey(), $obligation->getKey())->outstanding->minorUnits());
    }

    public function test_postgresql_concurrent_claim_can_move_certificate_only_once(): void
    {
        $this->requirePostgres();
        [$organization, $client, $certificate] = $this->fixture(withObligation: false);
        $firstRecipient = Client::factory()->forOrganization($organization)->create();
        $secondRecipient = Client::factory()->forOrganization($organization)->create();
        $transfer = app(CreateGiftCertificateTransfer::class)->handle($client, $certificate);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::claimInProcess($organization->getKey(), $firstRecipient->getKey(), $transfer->rawToken),
            static fn (): string => self::claimInProcess($organization->getKey(), $secondRecipient->getKey(), $transfer->rawToken),
        ]);

        self::assertNotContains('error', $results);
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'claimed:'))));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'validation')));
        self::assertSame(1, GiftCertificateMovement::query()
            ->where('certificate_id', $certificate->getKey())
            ->where('movement_type', GiftCertificateMovementType::Claimed->value)
            ->count());
    }

    public function test_postgresql_concurrent_claim_and_transfer_replacement_share_certificate_lock_order(): void
    {
        $this->requirePostgres();
        [$organization, $client, $certificate] = $this->fixture(withObligation: false);
        $recipient = Client::factory()->forOrganization($organization)->create();
        $transfer = app(CreateGiftCertificateTransfer::class)->handle($client, $certificate);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::claimInProcess($organization->getKey(), $recipient->getKey(), $transfer->rawToken),
            static fn (): string => self::replaceTransferInProcess($organization->getKey(), $client->getKey(), $certificate->getKey()),
        ]);

        self::assertNotContains('error', $results);
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'claimed:')))
            + count(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'replaced:'))));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'validation')));
        self::assertLessThanOrEqual(1, GiftCertificateClaim::query()
            ->where('certificate_id', $certificate->getKey())
            ->where('status', 'pending')
            ->count());
    }

    public function test_postgresql_concurrent_crm_sale_retry_creates_one_pending_purchase(): void
    {
        $this->requirePostgres();
        [$organization, $client, $certificate, , $admin] = $this->fixture(withObligation: false);
        $productId = PurchaseItem::query()->findOrFail($certificate->purchase_item_id)->sellable_id;

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::createCrmSaleInProcess(
                $organization->getKey(),
                $admin->getKey(),
                $client->getKey(),
                (int) $productId,
                'crm-concurrent-sale',
            ),
            static fn (): string => self::createCrmSaleInProcess(
                $organization->getKey(),
                $admin->getKey(),
                $client->getKey(),
                (int) $productId,
                'crm-concurrent-sale',
            ),
        ]);

        self::assertNotContains('error', $results);
        self::assertCount(2, array_filter($results, static fn (string $result): bool => str_starts_with($result, 'obligation:')));
        self::assertSame(1, Purchase::query()
            ->where('organization_id', $organization->getKey())
            ->where('checkout_idempotency_key', 'crm-concurrent-sale')
            ->count());
    }

    public function test_postgresql_certificate_history_is_append_only(): void
    {
        $this->requirePostgres();
        [$organization, $client, $certificate, $obligation] = $this->fixture();

        $entry = app(ApplyGiftCertificateToObligation::class)->handle(
            client: $client,
            certificate: $certificate,
            obligationId: $obligation->getKey(),
            amount: '60.00',
            currency: 'USD',
            idempotencyKey: 'gift-append-only',
        );
        $redemption = GiftCertificateRedemption::query()
            ->where('organization_id', $organization->getKey())
            ->where('financial_ledger_entry_id', $entry->getKey())
            ->firstOrFail();
        $movement = GiftCertificateMovement::query()
            ->where('organization_id', $organization->getKey())
            ->where('redemption_id', $redemption->getKey())
            ->where('movement_type', GiftCertificateMovementType::Redeemed->value)
            ->firstOrFail();

        try {
            DB::table('gift_certificate_movements')
                ->where('id', $movement->getKey())
                ->update(['amount_minor' => 1]);
            self::fail('Gift certificate movements must be immutable.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('gift_certificate_redemptions')
                ->where('id', $redemption->getKey())
                ->update(['amount_minor' => 1]);
            self::fail('Gift certificate redemptions must be immutable.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        self::assertSame(6000, (int) $movement->refresh()->amount_minor);
        self::assertSame(6000, (int) $redemption->refresh()->amount_minor);

        try {
            DB::table('gift_certificates')
                ->where('id', $certificate->getKey())
                ->update(['original_amount_minor' => 1]);
            self::fail('A certificate nominal must be immutable.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        self::assertSame(10000, (int) $certificate->refresh()->original_amount_minor);
    }

    private function fixture(bool $withObligation = true): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $giftService = Service::factory()->forOrganization($organization)->create([
            'catalog_type' => CatalogItemType::GiftCertificate->value,
            'price_minor' => 10000,
            'price_currency' => 'USD',
        ]);
        app(OrganizationContext::class)->set($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);

        $purchase = new Purchase;
        $purchase->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'status' => PurchaseStatus::Paid->value,
            'total_amount_minor' => 10000,
            'currency' => 'USD',
            'purchase_snapshot' => ['kind' => 'gift_certificate'],
            'paid_at' => now(),
        ])->save();
        $item = new PurchaseItem;
        $item->forceFill([
            'organization_id' => $organization->getKey(),
            'purchase_id' => $purchase->getKey(),
            'sellable_type' => Service::class,
            'sellable_id' => $giftService->getKey(),
            'quantity' => 1,
            'amount_minor' => 10000,
            'currency' => 'USD',
            'product_snapshot' => ['kind' => 'gift_certificate', 'catalog_type' => CatalogItemType::GiftCertificate->value, 'name' => 'Gift certificate'],
            'fulfillment_provider' => 'gift_certificate',
        ])->save();
        $fulfillment = new PurchaseFulfillment;
        $fulfillment->forceFill([
            'organization_id' => $organization->getKey(),
            'purchase_item_id' => $item->getKey(),
            'provider_type' => 'gift_certificate',
            'status' => CommerceFulfillmentStatus::Fulfilled->value,
            'attempts' => 1,
            'fulfilled_at' => now(),
        ])->save();
        $certificate = new GiftCertificate;
        $certificate->forceFill([
            'organization_id' => $organization->getKey(),
            'purchase_id' => $purchase->getKey(),
            'purchase_item_id' => $item->getKey(),
            'purchase_fulfillment_id' => $fulfillment->getKey(),
            'purchaser_client_id' => $client->getKey(),
            'current_holder_client_id' => $client->getKey(),
            'original_amount_minor' => 10000,
            'currency' => CurrencyCode::USD->value,
            'issued_at' => now(),
        ])->save();
        app(AppendGiftCertificateMovement::class)->handle(
            certificate: $certificate,
            type: GiftCertificateMovementType::Issued,
            amountMinor: 10000,
            currency: CurrencyCode::USD,
            fromHolderClientId: null,
            toHolderClientId: $client->getKey(),
            claimId: null,
            redemptionId: null,
            reversesMovementId: null,
            actorUserId: null,
            idempotencyKey: 'gift-certificate-concurrency-issued',
            occurredAt: now()->toImmutable(),
        );

        $obligation = null;
        if ($withObligation) {
            $service = Service::factory()->forOrganization($organization)->create([
                'price_minor' => 10000,
                'price_currency' => 'USD',
            ]);
            $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
            $booking = Booking::factory()
                ->forClient($client)
                ->forSpecialist($specialist)
                ->forService($service)
                ->create([
                    'status' => BookingStatus::Completed->value,
                    'starts_at' => now()->subHours(3),
                    'ends_at' => now()->subHours(2),
                    'blocking_ends_at' => now()->subHours(2),
                ]);
            $obligation = app(CreateFinancialObligation::class)->handle($admin, $booking);
        }

        return [$organization, $client, $certificate, $obligation, $admin];
    }

    private static function redeemInProcess(int $organizationId, int $clientId, int $certificateId, int $obligationId, string $key): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            $entry = app(ApplyGiftCertificateToObligation::class)->handle(
                client: Client::query()->findOrFail($clientId),
                certificate: $certificateId,
                obligationId: $obligationId,
                amount: '60.00',
                currency: 'USD',
                idempotencyKey: $key,
            );

            return 'entry:'.$entry->getKey();
        } catch (ValidationException) {
            return 'validation';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function claimInProcess(int $organizationId, int $clientId, string $rawToken): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            $certificate = app(ClaimGiftCertificate::class)->handle(
                Client::query()->findOrFail($clientId),
                $rawToken,
            );

            return 'claimed:'.$certificate->getKey();
        } catch (ValidationException) {
            return 'validation';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function replaceTransferInProcess(int $organizationId, int $clientId, int $certificateId): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            app(CreateGiftCertificateTransfer::class)->handle(
                Client::query()->findOrFail($clientId),
                GiftCertificate::query()->findOrFail($certificateId),
            );

            return 'replaced:'.$certificateId;
        } catch (ValidationException|AuthorizationException) {
            return 'validation';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function createCrmSaleInProcess(
        int $organizationId,
        int $adminId,
        int $clientId,
        int $productId,
        string $idempotencyKey,
    ): string {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            $obligation = app(CreateCrmGiftCertificateSale::class)->handle(
                actor: User::query()->findOrFail($adminId),
                clientId: $clientId,
                productId: $productId,
                idempotencyKey: $idempotencyKey,
            );

            return 'obligation:'.$obligation->getKey();
        } catch (ValidationException) {
            return 'validation';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Gift certificate concurrency requires PostgreSQL row locks and composite constraints.');
        }
    }
}
