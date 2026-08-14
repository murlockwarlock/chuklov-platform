import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { expect, test } from '@playwright/test';

type BookingFixture = {
    cookieName: string;
    cookieValue: string;
    serviceId: number;
    specialistId: number;
    date: string;
    bookingId: number | null;
};

function createBookingFixture(withBooking = false): BookingFixture {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $suffix = \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(12));
        \\App\\Modules\\Organizations\\Domain\\Models\\OrganizationFeatureFlag::query()->upsert([[
            'organization_id' => $organization->getKey(),
            'feature_key' => 'service_catalog',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['organization_id', 'feature_key'], ['enabled', 'updated_at']);
        $client = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Playwright Client '.$suffix,
            'email' => 'playwright-'.$suffix.'@example.test',
            'timezone' => 'UTC',
        ]);
        $specialist = \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'Playwright Specialist '.$suffix,
            'timezone' => 'UTC',
        ]);
        $service = \\App\\Modules\\Services\\Domain\\Models\\Service::factory()->forOrganization($organization)->create([
            'name' => 'Playwright Service '.$suffix,
            'formats' => ['office'],
        ]);
        \\App\\Modules\\Scheduling\\Domain\\Models\\SpecialistServiceAssignment::factory()
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        foreach (range(1, 7) as $weekday) {
            \\App\\Modules\\Scheduling\\Domain\\Models\\SpecialistWorkingHour::factory()
                ->forSpecialist($specialist)
                ->create([
                    'weekday' => $weekday,
                    'start_time' => '00:00',
                    'end_time' => '23:59',
                ]);
        }
        $date = \\Carbon\\CarbonImmutable::now('UTC')->addDay()->toDateString();
        $booking = null;
        if (getenv('PLAYWRIGHT_WITH_BOOKING') === '1') {
            $date = \\Carbon\\CarbonImmutable::now('UTC')->addDays(3)->toDateString();
            app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
            $booking = app(\\App\\Modules\\Scheduling\\Application\\CreateBooking::class)->handle(
                actor: $client,
                client: $client,
                specialist: $specialist,
                service: $service,
                startsAt: \\Carbon\\CarbonImmutable::parse($date.' 09:00:00', 'UTC'),
                format: \\App\\Modules\\Scheduling\\Domain\\Enums\\VisitFormat::Office,
                idempotencyKey: 'playwright-'.$suffix,
            );
        }
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
        $cookieValue = $encrypter->encrypt(
            \\Illuminate\\Cookie\\CookieValuePrefix::create($cookieName, $encrypter->getKey()).$sessionId,
            false,
        );
        echo json_encode([
            'cookieName' => $cookieName,
            'cookieValue' => $cookieValue,
            'serviceId' => $service->getKey(),
            'specialistId' => $specialist->getKey(),
            'date' => $date,
            'bookingId' => $booking?->getKey(),
        ]);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-${process.pid}`;
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
                PLAYWRIGHT_WITH_BOOKING: withBooking ? '1' : '0',
            },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (error) {
        const details = error as { stderr?: Buffer; stdout?: Buffer };
        throw new Error(`${details.stderr?.toString() ?? ''}${details.stdout?.toString() ?? ''}`);
    }
    const json = output.trim().split('\n').at(-1) ?? '';

    return JSON.parse(json) as BookingFixture;
}

test('client portal shell is responsive', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Личный кабинет' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Войти через тг' })).toBeVisible();
    await expect(page.getByLabel('Email')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Получить код' })).toBeVisible();
    await expect(page.getByText(/Responsive web|Shared runtime|secure foundation|client portal|milestone|flow version/i)).toHaveCount(0);
});

test('Telegram Mini App submits initData automatically without a second login action', async ({ page }) => {
    let authenticationRequests = 0;

    await page.route('https://telegram.org/js/telegram-web-app.js', async (route) => {
        await route.fulfill({
            contentType: 'application/javascript',
            body: 'window.Telegram = { WebApp: { initData: "verified-init-data", ready() {} } };',
        });
    });
    await page.route('**/portal/telegram/auth', async (route) => {
        authenticationRequests += 1;
        await route.fulfill({
            status: 200,
            headers: {
                'Content-Type': 'application/json',
                'Vary': 'Accept',
                'X-Inertia': 'true',
            },
            body: JSON.stringify({
                component: 'Services/Index',
                props: {
                    services: [],
                    portal: {
                        authenticated: true,
                        clientName: 'Telegram Client',
                        telegramAuthUrl: '/portal/telegram/auth',
                        telegramAuthError: null,
                        telegramWebRequestUrl: '/portal/auth/telegram/request',
                        telegramWebStatusUrl: '/portal/auth/telegram/status',
                        telegramWebUrl: null,
                        emailRequestUrl: '/portal/auth/email/request',
                        emailVerifyUrl: '/portal/auth/email/verify',
                        emailCodeSent: false,
                        telegramConnected: true,
                        telegramLinkRequestUrl: '/portal/channels/telegram/link',
                        telegramLinkUrl: null,
                        telegramLinkError: false,
                        onboardingUrl: '/portal/onboarding',
                        bookingUrl: null,
                        bookingsUrl: null,
                    },
                },
                url: '/',
                version: null,
            }),
        });
    });

    await page.goto('/');

    await expect(page.getByText('Вы вошли как Telegram Client.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Войти через Telegram' })).toHaveCount(0);
    expect(authenticationRequests).toBe(1);
});

test('authenticated client can complete the booking journey', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    const response = await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&specialist_id=${fixture.specialistId}&date_from=${fixture.date}&date_to=${fixture.date}&format=office`);
    expect(response?.status()).toBe(200);

    await expect(page.getByRole('heading', { name: 'Выберите удобное время' })).toBeVisible();
    await expect(page.locator('input[name="idempotency_key"], input[name="client_timezone"], select[name="meeting_link_mode"]')).toHaveCount(0);
    const firstSlot = page.locator('button[aria-pressed]').first();
    await expect(firstSlot).toBeVisible();
    await firstSlot.click();
    await page.getByRole('button', { name: 'Записаться' }).click();

    await expect(page.getByRole('status')).toContainText('Запись создана.');

    const secondSlot = page.locator('button[aria-pressed]').first();
    await expect(secondSlot).toBeVisible();
    await secondSlot.click();
    await page.getByRole('button', { name: 'Записаться' }).click();
    await expect(page.getByRole('status')).toContainText('Запись создана.');
    await page.getByRole('link', { name: 'Мои записи' }).click();
    await expect(page.getByText(/Playwright Service/)).toHaveCount(2);
});

test('authenticated client can manage an upcoming booking from My bookings', async ({ page }) => {
    const fixture = createBookingFixture(true);

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/portal/bookings');
    await expect(page.getByRole('heading', { name: 'Мои записи' })).toBeVisible();
    await expect(page.getByText(/Playwright Service/).first()).toBeVisible();

    await page.getByRole('link', { name: /Playwright Service/ }).first().click();
    await expect(page.getByRole('heading', { name: /Playwright Service/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Часовой пояс' })).toBeVisible();

    const alternateSlot = page.locator('button[aria-pressed]').first();
    await expect(alternateSlot).toBeVisible();
    await alternateSlot.click();
    await page.getByRole('button', { name: 'Перенести запись' }).click();
    await expect(page.getByText('История записи')).toBeVisible();
    await expect(page.getByText('Запись перенесена')).toBeVisible();

    await page.getByRole('combobox').last().selectOption('Asia/Almaty');
    await page.getByRole('button', { name: 'Сохранить' }).click();
    await expect(page.getByRole('combobox').last()).toHaveValue('Asia/Almaty');

    const secondAlternateSlot = page.locator('button[aria-pressed]').first();
    await expect(secondAlternateSlot).toBeVisible();
    await secondAlternateSlot.click();
    await page.getByRole('button', { name: 'Перенести запись' }).click();
    await expect(page.getByText('История записи')).toBeVisible();
    await expect(page.getByText('Запись перенесена')).toHaveCount(2);

    await page.getByRole('button', { name: 'Отменить запись' }).click();
    await expect(page.getByText('Отменена', { exact: true })).toBeVisible();
    await expect(page.locator('li').filter({ hasText: 'Запись отменена' })).toBeVisible();
});
