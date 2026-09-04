import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';

type BookingFixture = {
    cookieName: string;
    cookieValue: string;
    serviceId: number;
    serviceName: string;
    specialistId: number;
    specialistName: string;
    alternateSpecialistName: string | null;
    firstWorkingLocationName: string | null;
    secondWorkingLocationId: number | null;
    secondWorkingLocationName: string | null;
    date: string;
    bookingId: number | null;
};

type BookingFixtureOptions = {
    withBooking?: boolean;
    withCompanionMessages?: boolean;
    multipleChoices?: boolean;
    multipleLocations?: boolean;
    longServiceTitle?: boolean;
};

function createBookingFixture(options: BookingFixtureOptions | boolean = false): BookingFixture {
    const normalizedOptions = typeof options === 'boolean' ? { withBooking: options } : options;
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $suffix = \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(12));
        $multipleChoices = getenv('PLAYWRIGHT_MULTIPLE_CHOICES') === '1';
        $multipleLocations = getenv('PLAYWRIGHT_MULTIPLE_LOCATIONS') === '1';
        $longServiceTitle = getenv('PLAYWRIGHT_LONG_SERVICE_TITLE') === '1';
        $withCompanionMessages = getenv('PLAYWRIGHT_WITH_COMPANION_MESSAGES') === '1';
        \\App\\Modules\\Organizations\\Domain\\Models\\OrganizationFeatureFlag::query()->upsert([[
            'organization_id' => $organization->getKey(),
            'feature_key' => 'service_catalog',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['organization_id', 'feature_key'], ['enabled', 'updated_at']);
        foreach ([
            'offer' => 'offer_consent',
            'privacy' => 'privacy_consent',
            'medical_disclaimer' => 'medical_consent',
        ] as $documentType => $purpose) {
            $publishedDocumentExists = \\App\\Modules\\Identity\\Domain\\Models\\LegalDocument::query()
                ->where('organization_id', $organization->getKey())
                ->where('document_type', $documentType)
                ->where('status', \\App\\Modules\\Identity\\Domain\\Enums\\LegalDocumentStatus::Published)
                ->exists();
            if (! $publishedDocumentExists) {
                app(\\App\\Modules\\Identity\\Application\\PublishLegalDocument::class)->handle(
                    app(\\App\\Modules\\Identity\\Application\\CreatePlatformLegalDocumentDraft::class)->handle(
                        organization: $organization,
                        documentType: $documentType,
                        purpose: $purpose,
                        locale: 'ru',
                        version: '2026-09-03-'.$documentType,
                        content: 'Synthetic legal fixture.',
                        isRequired: true,
                    ),
                );
            }
        }
        $client = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Playwright Client '.$suffix,
            'email' => 'playwright-'.$suffix.'@example.test',
            'language' => 'ru',
            'timezone' => 'UTC',
        ]);
        $specialist = \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'Playwright Specialist '.$suffix,
            'timezone' => 'UTC',
        ]);
        $alternateSpecialist = null;
        if ($multipleChoices) {
            $alternateSpecialist = \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::factory()->forOrganization($organization)->create([
                'display_name' => 'Playwright Alternate Specialist '.$suffix,
                'timezone' => 'UTC',
            ]);
        }
        if ($withCompanionMessages) {
            config()->set('medical.keys.1', 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');
            $conversation = \\App\\Modules\\Conversations\\Domain\\Models\\Conversation::factory()
                ->forOrganization($organization)
                ->forClient($client)
                ->create([
                    'conversation_type' => \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationType::ClientCompanion,
                ]);
            $fence = str_repeat(chr(96), 3);
            $body = "# Безопасный ответ\n\n**Важная информация** и _пояснение_.\n\n- Первый пункт\n- Второй пункт\n\n[Безопасная HTTPS ссылка](https://example.test/secure)\n[HTTP ссылка не должна быть активной](http://example.test/insecure)\n[javascript ссылка не должна быть активной](javascript:alert(1))\n[data ссылка не должна быть активной](data:text/html,unsafe)\n[file ссылка не должна быть активной](file:///tmp/unsafe)\n[Относительная ссылка не должна быть активной](//example.test/insecure)\n[userinfo ссылка не должна быть активной](https://user:pass@example.test/insecure)\n\n".$fence."\n".str_repeat('TOKEN', 1400)."\n".$fence."\n\n<script>alert('unsafe')</script> https://example.test/".str_repeat('long-segment-', 80);
            app(\\App\\Modules\\Conversations\\Application\\RecordCompanionMessage::class)->handle(
                organizationId: $organization->getKey(),
                client: $client,
                conversation: $conversation,
                channel: 'portal',
                direction: \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationDirection::Outbound,
                authorType: \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationAuthorType::Ai,
                body: $body,
            );
        }
        $service = \\App\\Modules\\Services\\Domain\\Models\\Service::factory()->forOrganization($organization)->create([
            'name' => $longServiceTitle
                ? 'Индивидуальная консультация по глубоким и устойчивым изменениям в жизни '.$suffix
                : 'Playwright Service '.$suffix,
            'formats' => $multipleChoices ? ['office', 'online'] : ['office'],
        ]);
        \\App\\Modules\\Scheduling\\Domain\\Models\\SpecialistServiceAssignment::factory()
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        if ($alternateSpecialist !== null) {
            \\App\\Modules\\Scheduling\\Domain\\Models\\SpecialistServiceAssignment::factory()
                ->forSpecialist($alternateSpecialist)
                ->forService($service)
                ->create();
        }
        foreach (array_filter([$specialist, $alternateSpecialist]) as $availableSpecialist) {
            foreach (range(1, 7) as $weekday) {
                \\App\\Modules\\Scheduling\\Domain\\Models\\SpecialistWorkingHour::factory()
                    ->forSpecialist($availableSpecialist)
                    ->create([
                        'weekday' => $weekday,
                        'start_time' => '09:00',
                        'end_time' => '12:00',
                    ]);
            }
        }
        $workingLocations = [];
        $workingLocations[] = \\App\\Modules\\Scheduling\\Domain\\Models\\WorkingLocation::factory()
            ->forOrganization($organization)
            ->defaultOffice()
            ->create([
                'name' => 'Кабинет Алматы '.$suffix,
                'address' => 'ул. Абая, 10',
                'timezone' => 'UTC',
            ]);
        if ($multipleLocations) {
            $workingLocations[] = \\App\\Modules\\Scheduling\\Domain\\Models\\WorkingLocation::factory()
                ->forOrganization($organization)
                ->create([
                    'name' => 'Berlin Mitte '.$suffix,
                    'address' => 'Invalidenstraße 1, Berlin',
                    'timezone' => 'Europe/Berlin',
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
            'serviceName' => $service->name,
            'specialistId' => $specialist->getKey(),
            'specialistName' => $specialist->display_name,
            'alternateSpecialistName' => $alternateSpecialist?->display_name,
            'firstWorkingLocationName' => ($workingLocations[0] ?? null)?->name,
            'secondWorkingLocationId' => ($workingLocations[1] ?? null)?->getKey(),
            'secondWorkingLocationName' => ($workingLocations[1] ?? null)?->name,
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
                PLAYWRIGHT_WITH_BOOKING: normalizedOptions.withBooking ? '1' : '0',
                PLAYWRIGHT_WITH_COMPANION_MESSAGES: normalizedOptions.withCompanionMessages ? '1' : '0',
                PLAYWRIGHT_MULTIPLE_CHOICES: normalizedOptions.multipleChoices ? '1' : '0',
                PLAYWRIGHT_MULTIPLE_LOCATIONS: normalizedOptions.multipleLocations ? '1' : '0',
                PLAYWRIGHT_LONG_SERVICE_TITLE: normalizedOptions.longServiceTitle ? '1' : '0',
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

async function assertNoHorizontalOverflow(page: Page): Promise<void> {
    await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
}

async function acceptRequiredConsents(page: Page): Promise<void> {
    const checkbox = page.getByRole('checkbox', {
        name: 'Я ознакомился(лась) и принимаю обязательные документы',
        exact: true,
    });

    await expect(checkbox).toHaveCount(1);
    await checkbox.check();
}

async function assertReadableProgress(page: Page, expectedCount: number): Promise<void> {
    const labels = page.locator('.portal-booking-progress__label');

    await expect(labels).toHaveCount(expectedCount);
    expect(await labels.evaluateAll((elements) => elements.every((element) => {
        const styles = window.getComputedStyle(element);
        const bounds = element.getBoundingClientRect();

        return element.textContent?.trim() !== ''
            && element.scrollWidth <= element.clientWidth + 1
            && element.scrollHeight <= element.clientHeight + 1
            && bounds.left >= -1
            && bounds.right <= window.innerWidth + 1
            && styles.overflowX !== 'hidden';
    }))).toBe(true);
}

async function assertFullSelectedServiceTitle(page: Page, serviceName: string, testId = 'booking-selection-service'): Promise<void> {
    const service = page.getByTestId(testId);

    await expect(service).toHaveText(serviceName);
    expect(await service.evaluate((element) => {
        const styles = window.getComputedStyle(element);

        return element.scrollWidth <= element.clientWidth + 1
            && styles.whiteSpace !== 'nowrap'
            && styles.textOverflow !== 'ellipsis';
    })).toBe(true);
}

test('client portal shell is responsive', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Добро пожаловать' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'CHUKLOV' })).toBeVisible();
    await expect(page.getByRole('img', { name: 'CHUKLOV' })).toHaveAttribute('src', '/brand/chuklov-designer-logo-ru.jpg');
    await expect(page.getByRole('button', { name: 'Войти через Telegram' })).toBeVisible();
    await expect(page.getByLabel('Email')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Получить код' })).toBeVisible();
    await expect(page.getByText(/Личный кабинет|Вы вошли как|Продолжить/)).toHaveCount(0);
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
                component: 'Portal/Home',
                props: {
                    services: [],
                    upcomingBooking: null,
                    attribution: {
                        needsManualSource: false,
                    },
                    portal: {
                        authenticated: true,
                        clientName: 'Telegram Client',
                        locale: 'ru',
                        localeUrl: '/portal/locale',
                        urls: {
                            home: '/',
                            services: '/portal/services',
                            bookings: '/portal/bookings',
                            finance: '/portal/finance',
                            surveys: '/portal/surveys',
                            companion: '/portal/companion',
                            profile: '/portal/profile',
                            referrals: '/portal/referrals',
                            feedback: '/portal/feedback',
                            attribution: '/portal/attribution',
                            booking: '/portal/bookings/create',
                            b2b: '/portal/b2b',
                        },
                    },
                },
                url: '/',
                version: null,
            }),
        });
    });

    await page.goto('/');

    await expect(page.getByText('Добро пожаловать, Telegram Client')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Войти через Telegram' })).toHaveCount(0);
    expect(authenticationRequests).toBe(1);
});

test('Telegram Mini App B2B launch authenticates before showing the requested destination', async ({ page }) => {
    let authenticationRequests = 0;

    await page.route('https://telegram.org/js/telegram-web-app.js', async (route) => {
        await route.fulfill({
            contentType: 'application/javascript',
            body: 'window.Telegram = { WebApp: { initData: "verified-init-data", ready() {} } };',
        });
    });
    await page.route('**/portal/telegram/auth', async (route) => {
        authenticationRequests += 1;
        expect(route.request().postDataJSON().launchEntry).toBe('b2b');
        await route.fulfill({
            status: 200,
            headers: {
                'Content-Type': 'application/json',
                'Vary': 'Accept',
                'X-Inertia': 'true',
            },
            body: JSON.stringify({
                component: 'Portal/B2b',
                props: {
                    portal: {
                        authenticated: true,
                        clientName: 'Telegram Client',
                        locale: 'ru',
                        localeUrl: '/portal/locale',
                        urls: {
                            home: '/',
                            services: '/portal/services',
                            bookings: '/portal/bookings',
                            finance: '/portal/finance',
                            surveys: '/portal/surveys',
                            companion: '/portal/companion',
                            profile: '/portal/profile',
                            referrals: '/portal/referrals',
                            feedback: '/portal/feedback',
                            attribution: '/portal/attribution',
                            booking: '/portal/bookings/create',
                            b2b: '/portal/b2b',
                        },
                    },
                    authenticated: true,
                    b2bSpecialistAnswer: 'yes',
                    content: [],
                    specialists: [],
                    selectedSpecialistId: null,
                    availability: null,
                    availabilityRange: null,
                    configurationReady: false,
                    configurationIssue: null,
                    urls: {
                        answer: '/portal/profile/b2b-answer',
                        page: '/portal/b2b',
                        submit: '/portal/b2b/leads',
                        login: '/',
                    },
                },
                url: '/portal/b2b',
                version: null,
            }),
        });
    });

    await page.goto('/portal/telegram/launch/b2b');

    await expect(page.getByRole('heading', { name: 'Развить бизнес с CHUKLOV' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Войти через Telegram' })).toHaveCount(0);
    expect(authenticationRequests).toBe(1);
});

test('authenticated client gets the CHUKLOV navigation and can persist RU/EN', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Добро пожаловать, Playwright Client' })).toBeVisible();
    const russianTrigger = page.getByRole('button', { name: 'Русский', exact: true });
    await expect(russianTrigger).toBeVisible();
    await expect(page.getByRole('menu')).toHaveCount(0);

    if ((page.viewportSize()?.width ?? 0) >= 768) {
        await expect(page.getByRole('link', { name: 'Услуги' }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Профиль' }).first()).toBeVisible();
    } else {
        await expect(page.getByRole('navigation').last()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Профиль' }).last()).toBeVisible();
    }

    await russianTrigger.click();
    await expect(page.getByRole('menuitemradio', { name: 'Русский', exact: true })).toHaveAttribute('aria-checked', 'true');
    await page.getByRole('menuitemradio', { name: 'English', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Welcome, Playwright Client' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'English', exact: true })).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByRole('img', { name: 'CHUKLOV' })).toHaveAttribute('src', '/brand/chuklov-designer-logo-en.jpg');
    await page.getByRole('link', { name: 'Services' }).last().click();
    await expect(page.getByRole('heading', { name: 'Services' })).toBeVisible();
    await expect(page.locator('.portal-service-card').first().getByRole('link', { name: 'Book an appointment' })).toHaveAttribute('href', /service_id=/);
    const profileResponse = page.waitForResponse((response) =>
        response.url().endsWith('/portal/profile') && response.request().method() === 'GET' && response.status() === 200,
    );
    await page.getByRole('link', { name: 'Profile' }).last().click();
    await profileResponse;
    await expect(page).toHaveURL(/\/portal\/profile$/);
    await expect(page.getByRole('heading', { name: 'Profile', exact: true })).toBeVisible();
    await expect(page.getByText('Manage your contact details and preferences when you need to.')).toHaveCount(0);
});

test('feedback keeps the selected score visible and the portal within mobile bounds', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    for (const width of [390, 760]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto('/portal/feedback');
        const selectedScore = page.getByRole('radio', { name: '6', exact: true });
        await selectedScore.click();
        await expect(selectedScore).toHaveAttribute('aria-checked', 'true');
        await expect(selectedScore).toHaveAttribute('aria-pressed', 'true');
        await expect(selectedScore).toHaveClass(/portal-score-option--selected/);
        await expect(page.getByRole('radio', { name: '5', exact: true })).not.toHaveClass(/portal-score-option--selected/);
        await assertNoHorizontalOverflow(page);
    }
});

test('home keeps one primary booking action and makes referrals discoverable at target widths', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    for (const width of [390, 760]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto('/');
        await expect(page.getByTestId('home-booking-cta')).toHaveCount(1);
        await expect(page.getByRole('heading', { name: 'Пока нет предстоящих записей' })).toBeVisible();
        await expect(page.locator('.portal-bottom-nav')).toBeVisible();
        await assertNoHorizontalOverflow(page);

        const navigationItems = page.locator('.portal-bottom-nav__link');
        expect(await navigationItems.count()).toBe(6);
        expect(await navigationItems.evaluateAll((items) => {
            const widths = items.map((item) => item.getBoundingClientRect().width);
            const labels = items.map((item) => item.querySelector('.portal-bottom-nav__label'));

            return Math.max(...widths) - Math.min(...widths) <= 1
                && labels.every((label) => label !== null && label.getBoundingClientRect().height >= 24);
        })).toBe(true);

        await page.screenshot({ path: `/tmp/chuklov-portal-home-${width}.png`, fullPage: true });
    }

    await page.getByTestId('home-referrals-cta').click();
    await expect(page).toHaveURL(/\/portal\/referrals$/);
    await expect(page.getByRole('heading', { name: 'Пригласить друга' })).toBeVisible();
});

test('B2B answer stays in one journey and Profile shows the same compact classification', async ({ page }) => {
    const fixture = createBookingFixture({ multipleChoices: true });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/portal/b2b');
    await expect(page.getByText('Являетесь ли вы массажистом / специалистом по работе с телом?')).toBeVisible();
    await page.getByRole('radio', { name: 'Да', exact: true }).check();
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page).toHaveURL(/\/portal\/b2b$/);
    await expect(page.getByRole('heading', { name: 'Запросить разговор о бизнесе' })).toBeVisible();
    await expect(page.getByText('Вы указали: специалист по работе с телом.')).toBeVisible();
    await expect(page.getByText('Являетесь ли вы массажистом / специалистом по работе с телом?')).toHaveCount(0);
    await expect(page.locator('#b2b-specialist')).toBeVisible();
    const specialistOptions = await page.locator('#b2b-specialist option').allTextContents();
    expect(specialistOptions).toContain('Выберите специалиста');
    expect(specialistOptions).not.toContain('specialist_id');

    await page.getByRole('link', { name: 'Профиль' }).last().click();
    await expect(page).toHaveURL(/\/portal\/profile$/);
    await expect(page.getByRole('heading', { name: 'Профессиональный профиль' })).toBeVisible();
    await expect(page.getByText('Специалист по работе с телом: Да')).toBeVisible();
    await expect(page.getByText('Являетесь ли вы массажистом / специалистом по работе с телом?')).toHaveCount(0);
});

test('B2B no answer explains the next step without showing the sales-call form', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/portal/b2b');
    await page.getByRole('radio', { name: 'Нет', exact: true }).check();
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Что дальше' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Запросить разговор о бизнесе' })).toHaveCount(0);
    await expect(page.getByText('Являетесь ли вы массажистом / специалистом по работе с телом?')).toHaveCount(0);
});

test('assistant uses an attachment icon and portal selects keep human option labels', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/portal/companion');
    await expect(page.getByRole('button', { name: 'Прикрепить изображения' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Начать новый диалог' })).toBeVisible();
    await expect(page.getByText('Добавить изображения', { exact: true })).toHaveCount(0);
    await assertNoHorizontalOverflow(page);

    await page.goto('/portal/attribution');
    const sourceOptions = await page.locator('select option').allTextContents();
    expect(sourceOptions).toContain('Выберите вариант');
    expect(sourceOptions).toContain('По рекомендации знакомых');
    expect(sourceOptions).not.toContain('friend');
    expect(sourceOptions).not.toContain('social');
});

test('companion safely renders rich long messages without viewport overflow', async ({ page }) => {
    const fixture = createBookingFixture({ withCompanionMessages: true });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    for (const width of [390, 760]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto('/portal/companion');
        await expect(page.getByRole('heading', { name: 'Безопасный ответ' })).toBeVisible();
        await expect(page.locator('.portal-rich-text strong')).toHaveText('Важная информация');
        await expect(page.locator('.portal-rich-text__code')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Безопасная HTTPS ссылка' })).toHaveAttribute('href', 'https://example.test/secure');
        for (const label of [
            'HTTP ссылка не должна быть активной',
            'javascript ссылка не должна быть активной',
            'data ссылка не должна быть активной',
            'file ссылка не должна быть активной',
            'Относительная ссылка не должна быть активной',
            'userinfo ссылка не должна быть активной',
        ]) {
            await expect(page.getByRole('link', { name: label, exact: true })).toHaveCount(0);
            const renderedParagraph = page.locator('.portal-rich-text p').filter({ hasText: label });
            await expect(renderedParagraph).toHaveCount(1);
            await expect(renderedParagraph).toBeVisible();
        }
        await expect(page.locator('.portal-rich-text script')).toHaveCount(0);
        await assertNoHorizontalOverflow(page);
    }
});

test('authenticated client can complete the booking journey', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    const response = await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&date_from=${fixture.date}&date_to=${fixture.date}&format=office`);
    expect(response?.status()).toBe(200);

    await expect(page.getByRole('heading', { name: 'Выберите дату и время' })).toBeVisible();
    await expect(page.locator('#booking-specialist')).toHaveCount(0);
    await expect(page.getByText(/Playwright Specialist/)).toHaveCount(0);
    await expect(page.locator('input[name="date_from"], input[name="date_to"]')).toHaveCount(0);
    await expect(page.locator('input[name="idempotency_key"], input[name="client_timezone"], select[name="meeting_link_mode"]')).toHaveCount(0);
    const firstSlot = page.getByTestId('availability-slot').first();
    await expect(firstSlot).toBeVisible();
    await firstSlot.click();
    await expect(page.getByRole('button', { name: 'Продолжить' })).toBeEnabled();
    await page.getByRole('button', { name: 'Продолжить' }).click();
    await acceptRequiredConsents(page);
    await page.getByRole('button', { name: 'Подтвердить запись' }).click();

    await expect(page.getByRole('heading', { name: 'Запись создана.' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Выберите дату и время' })).toHaveCount(0);
    await expect(page.getByTestId('availability-slot')).toHaveCount(0);
    await page.getByRole('link', { name: 'Мои записи' }).last().click();
    await expect(page.getByText(/Playwright Service/)).toHaveCount(1);
});

test('client can change display timezone and choose a physical office', async ({ page }) => {
    const fixture = createBookingFixture({ multipleLocations: true });

    expect(fixture.firstWorkingLocationName).not.toBeNull();
    expect(fixture.secondWorkingLocationId).not.toBeNull();
    expect(fixture.secondWorkingLocationName).not.toBeNull();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&date_from=${fixture.date}&date_to=${fixture.date}&format=office`);

    const locationSelect = page.getByTestId('booking-working-location-select');
    await expect(locationSelect).toBeVisible();
    await locationSelect.selectOption(String(fixture.secondWorkingLocationId));
    await expect(locationSelect).toHaveValue(String(fixture.secondWorkingLocationId));
    await expect(page.getByText(fixture.secondWorkingLocationName as string, { exact: true })).toBeVisible();
    await expect(page.getByTestId('availability-slot').first()).toBeVisible();

    const timezoneSelect = page.getByTestId('booking-client-timezone-select');
    await expect(timezoneSelect).toHaveValue('UTC');
    await timezoneSelect.selectOption('Europe/Berlin');
    await expect(timezoneSelect).toHaveValue('Europe/Berlin');
    await expect(page.getByTestId('booking-client-timezone')).toHaveText('Europe/Berlin');
    await expect(page.getByTestId('availability-slot').first()).toBeVisible();
});

test('booking keeps its state while reviewing grouped legal documents', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&date_from=${fixture.date}&date_to=${fixture.date}&format=office`);
    await page.getByTestId('availability-slot').first().click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();

    const requiredCheckbox = page.getByRole('checkbox', {
        name: 'Я ознакомился(лась) и принимаю обязательные документы',
        exact: true,
    });
    const documentLinks = page.getByRole('button', { name: /Открыть:/ });
    const dialog = page.getByRole('dialog');

    await expect(documentLinks).toHaveCount(3);
    await expect(requiredCheckbox).not.toBeChecked();
    await documentLinks.first().click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading', { name: 'Оферта' })).toBeVisible();
    await expect(dialog.getByText('Synthetic legal fixture.')).toBeVisible();
    await dialog.getByRole('button', { name: 'Закрыть' }).first().click();
    await expect(dialog).toHaveCount(0);
    await expect(page.getByText(fixture.serviceName, { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Записаться', exact: true }).click();
    await expect(page.getByText('Подтвердите ознакомление с обязательными документами.', { exact: true })).toHaveCount(1);
    await requiredCheckbox.check();
    await expect(requiredCheckbox).toBeChecked();
    await assertNoHorizontalOverflow(page);
});

test('booking uses a service step and selected-day calendar before confirmation', async ({ page }) => {
    const fixture = createBookingFixture();
    const dateToValue = new Date(`${fixture.date}T00:00:00Z`);
    dateToValue.setUTCDate(dateToValue.getUTCDate() + 1);
    const dateTo = dateToValue.toISOString().slice(0, 10);
    const nextMonth = new Date(`${fixture.date.slice(0, 7)}-01T00:00:00Z`);
    nextMonth.setUTCMonth(nextMonth.getUTCMonth() + 1);
    const nextMonthFrom = nextMonth.toISOString().slice(0, 10);

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto(`/portal/bookings/create?date_from=${fixture.date}&date_to=${dateTo}`);
    await expect(page.getByRole('heading', { name: 'Выберите услугу' }).first()).toBeVisible();
    await expect(page.locator('select[name="service_id"]')).toHaveCount(0);
    await expect(page.locator('input[name="date_from"], input[name="date_to"]')).toHaveCount(0);
    await page.locator('.portal-booking-option').filter({ hasText: fixture.serviceName }).click();
    await page.getByRole('button', { name: 'Продолжить' }).click();

    await expect(page.getByRole('heading', { name: 'Выберите дату и время' })).toBeVisible();
    await expect(page.locator('.portal-slot-day')).toHaveCount(0);
    expect(await page.locator('.portal-calendar-card__day').count()).toBeGreaterThan(7);
    const outsideMonthDays = page.locator('.portal-calendar-card__day--outside');
    expect(await outsideMonthDays.count()).toBeGreaterThan(0);
    await expect(outsideMonthDays.first()).toBeDisabled();
    expect(await outsideMonthDays.first().evaluate((element) => window.getComputedStyle(element).pointerEvents)).not.toBe('none');
    await expect(page.locator('.portal-calendar-card__day--outside.portal-calendar-card__day--available')).toHaveCount(0);

    await page.getByRole('button', { name: 'Следующий месяц' }).click();
    await expect.poll(() => new URL(page.url()).searchParams.get('date_from')).toBe(nextMonthFrom);
    await expect(page.getByRole('heading', { name: 'Выберите дату и время' })).toBeVisible();

    const availableDays = page.locator('.portal-calendar-card__day--available');
    expect(await availableDays.count()).toBeGreaterThan(1);
    await availableDays.first().click();
    await expect(availableDays.first()).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.portal-time-card')).toHaveCount(1);

    const time = page.getByTestId('availability-slot').first();
    await expect(page.getByTestId('availability-slot')).toHaveCount(3);
    await expect(time).toHaveText(/^\d{2}:\d{2}$/);
    await expect(time).toHaveAttribute('aria-label', /UTC[+-]\d{2}:\d{2}/);
    await expect(page.getByText(/Ваш часовой пояс: UTC[+-]\d{2}:\d{2}/)).toBeVisible();
    await time.click();
    await expect(time).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: 'Продолжить' }).click();
    await expect(page.getByRole('heading', { name: 'Проверьте запись' })).toBeVisible();
    await expect(page.getByText(/Playwright Service/)).toBeVisible();
    await expect(page.getByText(/Playwright Specialist/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Изменить дату и время' })).toBeVisible();
});

test('booking shell stays readable at narrow Mini App widths', async ({ page }) => {
    const fixture = createBookingFixture();
    const dateToValue = new Date(`${fixture.date}T00:00:00Z`);
    dateToValue.setUTCDate(dateToValue.getUTCDate() + 1);
    const dateTo = dateToValue.toISOString().slice(0, 10);

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    for (const width of [320, 360]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto(`/portal/bookings/create?date_from=${fixture.date}&date_to=${dateTo}`);
        await expect(page.getByRole('heading', { name: 'Выберите услугу' }).first()).toBeVisible();
        await expect(page.locator('.portal-booking-progress__label')).toHaveCount(3);
        await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

        await page.locator('.portal-booking-option').filter({ hasText: fixture.serviceName }).click();
        await page.getByRole('button', { name: 'Продолжить' }).click();
        await expect(page.getByRole('heading', { name: 'Выберите дату и время' })).toBeVisible();
        await expect(page.locator('.portal-calendar-card__weekdays span')).toHaveCount(7);
        await expect(page.getByTestId('availability-slot').first()).toBeVisible();
        await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    }
});

test('long multi-specialist and multi-format booking stays fully readable at 320px', async ({ page }) => {
    const fixture = createBookingFixture({
        multipleChoices: true,
        longServiceTitle: true,
    });
    const dateToValue = new Date(`${fixture.date}T00:00:00Z`);
    dateToValue.setUTCDate(dateToValue.getUTCDate() + 1);
    const dateTo = dateToValue.toISOString().slice(0, 10);

    expect(fixture.alternateSpecialistName).not.toBeNull();
    await page.setViewportSize({ width: 320, height: 844 });
    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto(`/portal/bookings/create?date_from=${fixture.date}&date_to=${dateTo}`);
    await expect(page.getByRole('heading', { name: 'Выберите услугу' })).toBeVisible();
    await assertNoHorizontalOverflow(page);

    await page.locator('.portal-booking-option').filter({ hasText: fixture.serviceName }).click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Выберите специалиста' })).toBeVisible();
    await assertReadableProgress(page, 5);
    await assertFullSelectedServiceTitle(page, fixture.serviceName, 'booking-choice-service');
    await assertNoHorizontalOverflow(page);

    await page.locator('.portal-booking-option').filter({ hasText: fixture.specialistName }).click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Выберите формат' })).toBeVisible();
    await assertReadableProgress(page, 5);
    await assertFullSelectedServiceTitle(page, fixture.serviceName, 'booking-choice-service');
    await expect(page.getByTestId('booking-choice-specialist')).toHaveText(fixture.specialistName);
    await assertNoHorizontalOverflow(page);

    await page.getByRole('button', { name: 'В клинике', exact: true }).click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Выберите дату и время' })).toBeVisible();
    await assertReadableProgress(page, 5);
    await assertFullSelectedServiceTitle(page, fixture.serviceName);
    await expect(page.getByTestId('booking-selection-specialist')).toHaveText(fixture.specialistName);
    await expect(page.getByTestId('booking-selection-format')).toHaveText('В клинике');
    await expect(page.locator('.portal-calendar-card__weekdays span')).toHaveCount(7);
    await expect(page.getByTestId('availability-slot').first()).toBeVisible();
    await assertNoHorizontalOverflow(page);

    await page.getByTestId('availability-slot').first().click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Проверьте запись' })).toBeVisible();
    await expect(page.getByText(fixture.serviceName, { exact: true })).toBeVisible();
    await assertReadableProgress(page, 5);
    await assertNoHorizontalOverflow(page);

    await acceptRequiredConsents(page);
    await page.getByRole('button', { name: 'Подтвердить запись', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Запись создана.' })).toBeVisible();
    await expect(page.getByText(fixture.serviceName, { exact: true })).toBeVisible();
    await assertNoHorizontalOverflow(page);
});

test('authenticated client can manage an upcoming booking from My bookings', async ({ page }) => {
    const fixture = createBookingFixture(true);
    const earlierDate = new Date(`${fixture.date}T00:00:00Z`);
    earlierDate.setUTCDate(earlierDate.getUTCDate() - 1);
    const earlierDateValue = earlierDate.toISOString().slice(0, 10);

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/');
    await expect(page.getByText('Перенести', { exact: true })).toHaveCount(0);

    await page.goto('/portal/bookings');
    await expect(page.getByRole('heading', { name: 'Мои записи' })).toBeVisible();
    await expect(page.getByText(/Playwright Service/).first()).toBeVisible();

    await page.getByRole('link', { name: /Playwright Service/ }).first().click();
    await expect(page.getByRole('heading', { name: /Playwright Service/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Часовой пояс' })).toHaveCount(0);
    await expect(page.getByTestId('availability-slot')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Перенести', exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Перенести', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Выберите новое время' })).toBeVisible();
    const earlierDateButton = page.getByRole('button', { name: earlierDateValue, exact: true });
    await expect(earlierDateButton).toBeEnabled();
    await earlierDateButton.click();
    const alternateSlot = page.getByTestId('availability-slot').first();
    await expect(alternateSlot).toBeVisible();
    await expect(page.getByRole('button', { name: 'Перенести запись', exact: true })).toBeDisabled();
    await alternateSlot.click();
    await page.getByRole('button', { name: 'Перенести запись', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'История' })).toBeVisible();
    await expect(page.getByText('Запись перенесена')).toBeVisible();

    await page.getByRole('button', { name: 'Перенести', exact: true }).click();
    const originalDateButton = page.getByRole('button', { name: fixture.date, exact: true });
    await expect(originalDateButton).toBeEnabled();
    await originalDateButton.click();
    const secondAlternateSlot = page.getByTestId('availability-slot').nth(1);
    await expect(secondAlternateSlot).toBeVisible();
    await secondAlternateSlot.click();
    await page.getByRole('button', { name: 'Перенести запись', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'История' })).toBeVisible();
    await expect(page.getByText('Запись перенесена')).toHaveCount(2);

    await page.getByRole('button', { name: 'Отменить' }).click();
    await expect(page.getByText('Отменена', { exact: true })).toBeVisible();
    await expect(page.locator('li').filter({ hasText: 'Запись отменена' })).toBeVisible();
});

test('booking details stay readable at 320px Mini App width', async ({ page }) => {
    const fixture = createBookingFixture(true);

    await page.setViewportSize({ width: 320, height: 844 });
    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/portal/bookings');
    await page.getByRole('link', { name: /Playwright Service/ }).first().click();
    await expect(page.getByRole('heading', { name: /Playwright Service/ })).toBeVisible();
    await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

    await page.getByRole('button', { name: 'Перенести', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Выберите новое время' })).toBeVisible();
    await expect(page.locator('.portal-calendar-card__weekdays span')).toHaveCount(7);
    await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
