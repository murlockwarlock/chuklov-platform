import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { expect, test } from '@playwright/test';

type GiftCertificateFixture = {
    cookieName: string;
    buyerCookieValue: string;
    recipientCookieValue: string;
    obligationServiceName: string;
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
    await expect(page.getByTestId('gift-certificate-card').first()).toContainText('100');

    await page.getByRole('button', { name: 'Подарить сертификат', exact: true }).click();
    const transferLink = page.getByTestId('gift-transfer-link');
    await expect(transferLink).toBeVisible();
    const claimUrl = await transferLink.getAttribute('href');
    expect(claimUrl).toMatch(/\/gift-certificates\/claim\/[a-f0-9]{64}$/);

    await page.context().addCookies([
        {
            name: fixture.cookieName,
            value: fixture.recipientCookieValue,
            url: baseUrl,
        },
    ]);
    await page.goto(claimUrl as string);
    await expect(page.getByRole('heading', { name: 'Вам подарили сертификат', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Получить сертификат', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/gift-certificates$/);

    const certificateCard = page.getByTestId('gift-certificate-card').first();
    await expect(certificateCard).toContainText('100');
    await expect(certificateCard.getByRole('button', { name: 'Применить', exact: true })).toBeVisible();
    await certificateCard.getByRole('button', { name: 'Применить', exact: true }).click();
    await expect(certificateCard).toContainText('40');

    await page.goto('/portal/finance');
    const obligation = page.locator('section.portal-content-section').filter({ hasText: fixture.obligationServiceName }).first();
    await expect(obligation).toContainText('Оплачено');
    await expect(obligation.getByText('Осталось', { exact: true }).locator('..')).toContainText('0');
});
