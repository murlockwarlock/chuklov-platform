import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { expect, test } from '@playwright/test';

type BookingFixture = {
    cookieName: string;
    cookieValue: string;
    serviceId: number;
    specialistId: number;
    date: string;
};

function createBookingFixture(): BookingFixture {
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
    await expect(page.getByRole('heading', { name: 'Chuklov Client Portal' })).toBeVisible();
});

test('authenticated client can complete the booking journey', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    const response = await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&specialist_id=${fixture.specialistId}&date_from=${fixture.date}&date_to=${fixture.date}&format=office&display_timezone=UTC`);
    expect(response?.status()).toBe(200);

    if (await page.getByRole('heading', { name: 'Find a suitable time' }).count() === 0) {
        throw new Error(`Booking page did not render at ${page.url()}: ${await page.locator('body').innerText()}`);
    }
    await expect(page.getByRole('heading', { name: 'Find a suitable time' })).toBeVisible();
    const firstSlot = page.locator('button[aria-pressed]').first();
    await expect(firstSlot).toBeVisible();
    await firstSlot.click();
    await page.getByRole('button', { name: 'Create booking' }).click();

    await expect(page.getByRole('status')).toContainText('Your booking request was created.');
});
