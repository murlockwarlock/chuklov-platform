import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { expect, test, type Locator } from '@playwright/test';

type GiftCertificateFixture = {
    cookieName: string;
    buyerCookieValue: string;
    recipientCookieValue: string;
    obligationServiceName: string;
    purchaseId: number;
    certificateId: number;
    buyerId: number;
    recipientId: number;
    obligationId: number;
};

type GiftCertificateState = {
    purchaseStatus: string;
    fulfillmentStatus: string;
    purchaseCertificateCount: number;
    issuedCount: number;
    transferCount: number;
    claimedCount: number;
    holderClientId: number;
    originalAmountMinor: number;
    currency: string;
    balanceMinor: number;
    claimStatus: string | null;
    claimRecipientId: number | null;
    tokenHashMatches: boolean;
    redemptionCount: number;
    redemptionAmountMinor: number | null;
    redemptionHolderClientId: number | null;
    redemptionLedgerType: string | null;
    redemptionLedgerSource: string | null;
    redemptionLedgerSettlementAmountMinor: number | null;
    obligationOutstandingMinor: number;
    obligationStatus: string;
    movements: Array<{ type: string; amountMinor: number }>;
};

function createGiftCertificateFixture(): GiftCertificateFixture {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $suffix = \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(12));
        $admin = \\App\\Models\\User::factory()->forOrganization($organization)->create();
        $buyer = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Gift buyer '.$suffix,
            'email' => 'gift-buyer-'.$suffix.'@example.test',
            'language' => 'ru',
            'timezone' => 'UTC',
        ]);
        $recipient = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Gift recipient '.$suffix,
            'email' => 'gift-recipient-'.$suffix.'@example.test',
            'language' => 'ru',
            'timezone' => 'UTC',
        ]);
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        app(\\App\\Modules\\Finance\\Application\\SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);
        $credential = \\App\\Modules\\Security\\Domain\\Models\\OrganizationCredential::query()->firstOrNew([
            'organization_id' => $organization->getKey(),
            'provider' => 'lava',
            'credential_name' => 'default',
        ]);
        $credential->forceFill([
            'organization_id' => $organization->getKey(),
            'revision_id' => \\Illuminate\\Support\\Str::uuid()->toString(),
            'status' => \\App\\Modules\\Security\\Domain\\Enums\\CredentialStatus::Active->value,
            'last_rotated_at' => now(),
            'credentials' => ['api_key' => 'playwright-lava-key', 'webhook_api_key' => 'playwright-lava-webhook-key'],
        ])->save();
        $giftService = \\App\\Modules\\Services\\Domain\\Models\\Service::factory()->forOrganization($organization)->create([
            'name' => 'Подарочный сертификат '.$suffix,
            'catalog_type' => \\App\\Modules\\Services\\Domain\\Enums\\CatalogItemType::GiftCertificate->value,
            'price_minor' => 10000,
            'price_currency' => 'USD',
        ]);
        $mapping = new \\App\\Modules\\Commerce\\Domain\\Models\\PaymentProviderOfferMapping;
        $mapping->forceFill([
            'organization_id' => $organization->getKey(),
            'gateway' => 'lava',
            'sellable_type' => \\App\\Modules\\Services\\Domain\\Models\\Service::class,
            'sellable_id' => $giftService->getKey(),
            'currency' => 'USD',
            'external_offer_id' => \\Illuminate\\Support\\Str::uuid()->toString(),
            'is_active' => true,
        ])->save();
        \\Illuminate\\Support\\Facades\\Http::fake(static fn () => \\Illuminate\\Support\\Facades\\Http::response([
            'id' => \\Illuminate\\Support\\Str::uuid()->toString(),
            'status' => 'in-progress',
            'paymentUrl' => 'https://pay.lava.top/playwright-gift-certificate',
        ], 201));
        $checkout = app(\\App\\Modules\\Commerce\\Application\\StartPurchaseCheckout::class)->giftCertificate(
            organization: $organization,
            client: $buyer,
            product: $giftService,
            gateway: 'lava',
            idempotencyKey: 'playwright-gift-checkout-'.$suffix,
            buyerEmail: (string) $buyer->email,
        );
        app(\\App\\Modules\\Finance\\Application\\RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $checkout->purchase->obligation()->firstOrFail(),
            amount: '100.00',
            currency: 'USD',
            paymentMethod: \\App\\Modules\\Finance\\Domain\\Enums\\PaymentMethod::Cash,
            occurredAt: now(),
            note: null,
            receipt: null,
            idempotencyKey: 'playwright-gift-settlement-'.$suffix,
        );
        $certificate = \\App\\Modules\\Commerce\\Domain\\Models\\GiftCertificate::query()
            ->where('purchase_id', $checkout->purchase->getKey())
            ->sole();
        $service = \\App\\Modules\\Services\\Domain\\Models\\Service::factory()->forOrganization($organization)->create([
            'name' => 'Консультация для сертификата '.$suffix,
            'formats' => ['office'],
            'price_minor' => 6000,
            'price_currency' => 'USD',
        ]);
        $specialist = \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::factory()->forOrganization($organization)->create([
            'timezone' => 'UTC',
        ]);
        $booking = \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::factory()
            ->forOrganization($organization)
            ->forClient($recipient)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => \\App\\Modules\\Scheduling\\Domain\\Enums\\BookingStatus::Completed->value,
                'starts_at' => now()->subHours(2),
                'ends_at' => now()->subHour(),
                'blocking_ends_at' => now()->subHour(),
            ]);
        $obligation = app(\\App\\Modules\\Finance\\Application\\CreateFinancialObligation::class)->handle($admin, $booking);
        if ($obligation === null) {
            throw new \\RuntimeException('The gift certificate E2E obligation was not created.');
        }
        $makeCookie = static function (\\App\\Modules\\Identity\\Domain\\Models\\Client $client): string {
            $sessionId = \\Illuminate\\Support\\Str::random(40);
            $sessionData = json_encode([
                '_token' => \\Illuminate\\Support\\Str::random(40),
                'client_portal' => ['client_id' => $client->getKey()],
            ], JSON_THROW_ON_ERROR);
            $sessionPayload = config('session.encrypt')
                ? app('encrypter')->encrypt($sessionData)
                : $sessionData;
            \\Illuminate\\Support\\Facades\\DB::table('sessions')->insert([
                'id' => $sessionId,
                'user_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Playwright',
                'payload' => base64_encode($sessionPayload),
                'last_activity' => time(),
            ]);
            $cookieName = (string) config('session.cookie');
            $encrypter = app('encrypter');

            return $encrypter->encrypt(
                \\Illuminate\\Cookie\\CookieValuePrefix::create($cookieName, $encrypter->getKey()).$sessionId,
                false,
            );
        };
        echo json_encode([
            'cookieName' => (string) config('session.cookie'),
            'buyerCookieValue' => $makeCookie($buyer),
            'recipientCookieValue' => $makeCookie($recipient),
            'obligationServiceName' => $service->name,
            'purchaseId' => (int) $checkout->purchase->getKey(),
            'certificateId' => (int) $certificate->getKey(),
            'buyerId' => (int) $buyer->getKey(),
            'recipientId' => (int) $recipient->getKey(),
            'obligationId' => (int) $obligation->getKey(),
        ], JSON_THROW_ON_ERROR);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-gift-${process.pid}`;
    mkdirSync(psyshConfigDirectory, { recursive: true });
    let output: string;

    try {
        output = execFileSync('php', ['artisan', 'tinker', '--execute', php], {
            encoding: 'utf8',
            env: {
                ...process.env,
                XDG_CONFIG_HOME: psyshConfigDirectory,
                DB_CONNECTION: 'pgsql',
                DB_HOST: '127.0.0.1',
                DB_PORT: '5432',
                DB_DATABASE: process.env.DB_DATABASE ?? 'chuklov',
                DB_USERNAME: process.env.DB_USERNAME ?? 'chuklov',
                DB_PASSWORD: process.env.DB_PASSWORD ?? 'chuklov_local',
            },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (error) {
        const details = error as { stderr?: Buffer; stdout?: Buffer };
        throw new Error(`${details.stderr?.toString() ?? ''}${details.stdout?.toString() ?? ''}`);
    }

    return JSON.parse(output.trim().split('\n').at(-1) ?? '') as GiftCertificateFixture;
}

function readGiftCertificateState(fixture: GiftCertificateFixture, claimToken: string): GiftCertificateState {
    const php = `
        $purchase = \\App\\Modules\\Commerce\\Domain\\Models\\Purchase::query()->findOrFail(${fixture.purchaseId});
        $certificate = \\App\\Modules\\Commerce\\Domain\\Models\\GiftCertificate::query()->findOrFail(${fixture.certificateId});
        $fulfillment = \\App\\Modules\\Commerce\\Domain\\Models\\PurchaseFulfillment::query()
            ->where('purchase_id', ${fixture.purchaseId})
            ->sole();
        $movements = \\Illuminate\\Support\\Facades\\DB::table('gift_certificate_movements')
            ->where('certificate_id', ${fixture.certificateId})
            ->orderBy('id')
            ->get(['movement_type', 'amount_minor']);
        $claim = \\App\\Modules\\Commerce\\Domain\\Models\\GiftCertificateClaim::query()
            ->where('certificate_id', ${fixture.certificateId})
            ->sole();
        $redemptions = \\App\\Modules\\Commerce\\Domain\\Models\\GiftCertificateRedemption::query()
            ->where('certificate_id', ${fixture.certificateId})
            ->get();
        $redemption = $redemptions->first();
        $ledger = $redemption === null
            ? null
            : \\App\\Modules\\Finance\\Domain\\Models\\FinancialLedgerEntry::query()->findOrFail($redemption->financial_ledger_entry_id);
        $obligation = \\App\\Modules\\Finance\\Domain\\Models\\FinancialObligation::query()->findOrFail(${fixture.obligationId});
        $reconciliation = app(\\App\\Modules\\Finance\\Application\\ReconcileFinancialObligation::class)
            ->handle((int) $obligation->organization_id, (int) $obligation->getKey());

        echo json_encode([
            'purchaseStatus' => (string) $purchase->getRawOriginal('status'),
            'fulfillmentStatus' => (string) $fulfillment->getRawOriginal('status'),
            'purchaseCertificateCount' => \\App\\Modules\\Commerce\\Domain\\Models\\GiftCertificate::query()
                ->where('purchase_id', ${fixture.purchaseId})
                ->count(),
            'issuedCount' => \\Illuminate\\Support\\Facades\\DB::table('gift_certificate_movements')
                ->where('certificate_id', ${fixture.certificateId})
                ->where('movement_type', 'issued')
                ->count(),
            'transferCount' => \\Illuminate\\Support\\Facades\\DB::table('gift_certificate_movements')
                ->where('certificate_id', ${fixture.certificateId})
                ->where('movement_type', 'transferred')
                ->count(),
            'claimedCount' => \\Illuminate\\Support\\Facades\\DB::table('gift_certificate_movements')
                ->where('certificate_id', ${fixture.certificateId})
                ->where('movement_type', 'claimed')
                ->count(),
            'holderClientId' => (int) $certificate->current_holder_client_id,
            'originalAmountMinor' => (int) $certificate->original_amount_minor,
            'currency' => (string) $certificate->getRawOriginal('currency'),
            'balanceMinor' => app(\\App\\Modules\\Commerce\\Application\\GiftCertificateBalanceProjection::class)
                ->balance($certificate)
                ->minorUnits(),
            'claimStatus' => (string) $claim->getRawOriginal('status'),
            'claimRecipientId' => $claim->claimed_client_id === null ? null : (int) $claim->claimed_client_id,
            'tokenHashMatches' => hash_equals((string) $claim->token_hash, hash('sha256', getenv('GIFT_CERTIFICATE_E2E_TOKEN') ?: '')),
            'redemptionCount' => $redemptions->count(),
            'redemptionAmountMinor' => $redemption === null ? null : (int) $redemption->amount_minor,
            'redemptionHolderClientId' => $redemption === null ? null : (int) $redemption->holder_client_id,
            'redemptionLedgerType' => $ledger === null ? null : (string) $ledger->getRawOriginal('entry_type'),
            'redemptionLedgerSource' => $ledger === null ? null : (string) $ledger->getRawOriginal('source'),
            'redemptionLedgerSettlementAmountMinor' => $ledger === null ? null : (int) $ledger->settlement_amount_minor,
            'obligationOutstandingMinor' => $reconciliation->outstanding->minorUnits(),
            'obligationStatus' => $reconciliation->status->value,
            'movements' => $movements->map(static fn (object $movement): array => [
                'type' => (string) $movement->movement_type,
                'amountMinor' => (int) $movement->amount_minor,
            ])->all(),
        ], JSON_THROW_ON_ERROR);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-gift-state-${process.pid}`;
    mkdirSync(psyshConfigDirectory, { recursive: true });
    let output: string;

    try {
        output = execFileSync('php', ['artisan', 'tinker', '--execute', php], {
            encoding: 'utf8',
            env: {
                ...process.env,
                XDG_CONFIG_HOME: psyshConfigDirectory,
                DB_CONNECTION: 'pgsql',
                DB_HOST: '127.0.0.1',
                DB_PORT: '5432',
                DB_DATABASE: process.env.DB_DATABASE ?? 'chuklov',
                DB_USERNAME: process.env.DB_USERNAME ?? 'chuklov',
                DB_PASSWORD: process.env.DB_PASSWORD ?? 'chuklov_local',
                GIFT_CERTIFICATE_E2E_TOKEN: claimToken,
            },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (error) {
        const details = error as { stderr?: Buffer; stdout?: Buffer };
        throw new Error(`${details.stderr?.toString() ?? ''}${details.stdout?.toString() ?? ''}`);
    }

    return JSON.parse(output.trim().split('\n').at(-1) ?? '') as GiftCertificateState;
}

async function expectCertificateAmounts(certificateCard: Locator, balance: number): Promise<void> {
    await expect(certificateCard.locator('h2')).toContainText(new RegExp(`${balance}[,.]00`));
    await expect(certificateCard.getByText(/^Номинал:/)).toContainText(/100[,.]00/);
    await expect(certificateCard.getByText('USD', { exact: true })).toBeVisible();
}

test('client can transfer, claim, and apply a gift certificate', async ({ page }) => {
    test.setTimeout(90_000);

    const fixture = createGiftCertificateFixture();
    const baseUrl = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000';

    await page.context().addCookies([
        {
            name: fixture.cookieName,
            value: fixture.buyerCookieValue,
            url: baseUrl,
        },
    ]);
    await page.goto('/portal/gift-certificates');
    await expect(page.getByRole('heading', { name: 'Сертификаты', exact: true })).toBeVisible();
    const buyerCertificate = page.getByTestId('gift-certificate-card').first();
    await expectCertificateAmounts(buyerCertificate, 100);
    await page.reload();
    await expectCertificateAmounts(page.getByTestId('gift-certificate-card').first(), 100);

    await page.getByRole('button', { name: 'Подарить сертификат', exact: true }).click();
    const transferLink = page.getByTestId('gift-transfer-link');
    await expect(transferLink).toBeVisible();
    const claimUrl = await transferLink.getAttribute('href');
    expect(claimUrl).not.toBeNull();
    const claimLocation = new URL(claimUrl as string, page.url());
    expect(claimLocation.pathname).toBe('/gift-certificates/claim');
    expect(claimLocation.search).toBe('');
    expect(claimLocation.hash).toMatch(/^#token=[a-f0-9]{64}$/);
    const claimToken = claimLocation.hash.slice('#token='.length);

    const pendingState = readGiftCertificateState(fixture, claimToken);
    expect(pendingState.purchaseStatus).toBe('paid');
    expect(pendingState.fulfillmentStatus).toBe('fulfilled');
    expect(pendingState.purchaseCertificateCount).toBe(1);
    expect(pendingState.issuedCount).toBe(1);
    expect(pendingState.transferCount).toBe(1);
    expect(pendingState.claimedCount).toBe(0);
    expect(pendingState.holderClientId).toBe(fixture.buyerId);
    expect(pendingState.originalAmountMinor).toBe(10000);
    expect(pendingState.currency).toBe('USD');
    expect(pendingState.balanceMinor).toBe(10000);
    expect(pendingState.claimStatus).toBe('pending');
    expect(pendingState.claimRecipientId).toBeNull();
    expect(pendingState.tokenHashMatches).toBe(true);
    expect(pendingState.redemptionCount).toBe(0);
    expect(pendingState.movements).toEqual([
        { type: 'issued', amountMinor: 10000 },
        { type: 'transferred', amountMinor: 0 },
    ]);

    await page.reload();
    const pendingCertificate = page.getByTestId('gift-certificate-card').first();
    await expectCertificateAmounts(pendingCertificate, 100);
    await expect(pendingCertificate).toContainText('Ожидает получателя');
    await expect(pendingCertificate.getByRole('button', { name: 'Применить', exact: true })).toHaveCount(0);

    await page.context().addCookies([
        {
            name: fixture.cookieName,
            value: fixture.recipientCookieValue,
            url: baseUrl,
        },
    ]);
    await page.goto(claimUrl as string);
    await expect(page.getByRole('heading', { name: 'Вам подарили сертификат', exact: true })).toBeVisible();
    await expect(page.locator('section.portal-container').first()).toContainText(/100[,.]00/);
    await page.getByRole('button', { name: 'Получить сертификат', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/gift-certificates$/);

    const certificateCard = page.getByTestId('gift-certificate-card').first();
    await expectCertificateAmounts(certificateCard, 100);
    await expect(certificateCard.getByRole('button', { name: 'Применить', exact: true })).toBeVisible();
    await page.reload();
    await expectCertificateAmounts(page.getByTestId('gift-certificate-card').first(), 100);

    await certificateCard.getByRole('button', { name: 'Применить', exact: true }).click();
    await expectCertificateAmounts(certificateCard, 40);
    await page.reload();
    await expectCertificateAmounts(page.getByTestId('gift-certificate-card').first(), 40);

    await page.goto('/portal/finance');
    const obligation = page.locator('section.portal-content-section').filter({ hasText: fixture.obligationServiceName }).first();
    await expect(obligation).toContainText('Оплачено');
    await expect(obligation.getByText('Осталось', { exact: true }).locator('..')).toContainText('0');
    await page.reload();
    await expect(obligation).toContainText('Оплачено');
    await expect(obligation.getByText('Осталось', { exact: true }).locator('..')).toContainText('0');

    const redeemedState = readGiftCertificateState(fixture, claimToken);
    expect(redeemedState.purchaseStatus).toBe('paid');
    expect(redeemedState.fulfillmentStatus).toBe('fulfilled');
    expect(redeemedState.purchaseCertificateCount).toBe(1);
    expect(redeemedState.issuedCount).toBe(1);
    expect(redeemedState.transferCount).toBe(1);
    expect(redeemedState.claimedCount).toBe(1);
    expect(redeemedState.holderClientId).toBe(fixture.recipientId);
    expect(redeemedState.originalAmountMinor).toBe(10000);
    expect(redeemedState.currency).toBe('USD');
    expect(redeemedState.balanceMinor).toBe(4000);
    expect(redeemedState.claimStatus).toBe('claimed');
    expect(redeemedState.claimRecipientId).toBe(fixture.recipientId);
    expect(redeemedState.tokenHashMatches).toBe(true);
    expect(redeemedState.redemptionCount).toBe(1);
    expect(redeemedState.redemptionAmountMinor).toBe(6000);
    expect(redeemedState.redemptionHolderClientId).toBe(fixture.recipientId);
    expect(redeemedState.redemptionLedgerType).toBe('gift_certificate_redemption');
    expect(redeemedState.redemptionLedgerSource).toBe('gift_certificate');
    expect(redeemedState.redemptionLedgerSettlementAmountMinor).toBe(6000);
    expect(redeemedState.obligationOutstandingMinor).toBe(0);
    expect(redeemedState.obligationStatus).toBe('settled');
    expect(redeemedState.movements).toEqual([
        { type: 'issued', amountMinor: 10000 },
        { type: 'transferred', amountMinor: 0 },
        { type: 'claimed', amountMinor: 0 },
        { type: 'redeemed', amountMinor: 6000 },
    ]);
    const balanceFromMovements = redeemedState.movements.reduce((balance, movement) => {
        if (movement.type === 'issued' || movement.type === 'redemption_reversed') {
            return balance + movement.amountMinor;
        }

        if (movement.type === 'redeemed') {
            return balance - movement.amountMinor;
        }

        return balance;
    }, 0);
    expect(balanceFromMovements).toBe(4000);
});
