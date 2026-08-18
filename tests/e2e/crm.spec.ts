import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';

type CrmFixture = {
    email: string;
    password: string;
    clientId: number;
    serviceId: number;
    specialistId: number;
    clientName: string;
    serviceName: string;
    specialistName: string;
    contentSectionId: number;
    contentSectionTitle: string;
    attachmentFilename: string;
};

function createCrmFixture(): CrmFixture {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $suffix = \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(12));
        $email = 'playwright-crm-'.$suffix.'@example.test';
        $password = 'password';
        $admin = \\App\\Models\\User::factory()->forOrganization($organization)->create(['email' => $email]);
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        \\Illuminate\\Support\\Facades\\RateLimiter::clear('livewire-rate-limiter:'.sha1('Filament\\Auth\\Pages\\Login|authenticate|127.0.0.1'));
        \\App\\Modules\\Organizations\\Domain\\Models\\OrganizationFeatureFlag::query()->upsert([[
            'organization_id' => $organization->getKey(),
            'feature_key' => 'service_catalog',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['organization_id', 'feature_key'], ['enabled', 'updated_at']);
        \\App\\Modules\\Organizations\\Domain\\Models\\OrganizationFeatureFlag::query()->upsert([[
            'organization_id' => $organization->getKey(),
            'feature_key' => 'client_records',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['organization_id', 'feature_key'], ['enabled', 'updated_at']);
        $client = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
            'full_name' => 'CRM Клиент '.$suffix,
        ]);
        $specialist = \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'CRM Специалист '.$suffix,
            'timezone' => 'UTC',
        ]);
        $service = \\App\\Modules\\Services\\Domain\\Models\\Service::factory()->forOrganization($organization)->create([
            'name' => 'CRM Услуга '.$suffix,
            'formats' => ['office'],
        ]);
        $contentSection = \\App\\Modules\\Content\\Domain\\Models\\ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'О нашей академии '.$suffix,
            'body' => 'Описание академии для проверки CRM.',
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
        config()->set('medical.keys.1', 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');
        app(\\App\\Modules\\Sessions\\Application\\CreateSession::class)->handle(
            $admin,
            $client,
            new \\App\\Modules\\Sessions\\Application\\DTOs\\CreateSessionCommand(
                specialistId: $specialist->getKey(),
                occurredAt: \\Illuminate\\Support\\Carbon::parse('2026-08-10 09:00:00', 'UTC'),
                pain: 'Предыдущая запись о боли',
                result: 'Предыдущий подтверждённый результат',
            ),
        );
        $attachmentFilename = 'Заключение '.$suffix.'.pdf';
        \\App\\Modules\\Attachments\\Domain\\Models\\MedicalAttachment::query()->create([
            'uuid' => (string) \\Illuminate\\Support\\Str::uuid(),
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'uploaded_by_user_id' => $admin->getKey(),
            'attachment_type' => \\App\\Modules\\Attachments\\Domain\\Enums\\AttachmentType::MedicalReport,
            'disk' => 'private',
            'storage_path' => 'medical/attachments/'.$organization->getKey().'/'.\\Illuminate\\Support\\Str::uuid().'.pdf',
            'original_filename' => $attachmentFilename,
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'sha256_checksum' => hash('sha256', $suffix),
            'scan_status' => \\App\\Modules\\Attachments\\Domain\\Enums\\AttachmentScanStatus::Cleared,
            'scanned_at' => now(),
        ]);
        echo json_encode([
            'email' => $email,
            'password' => $password,
            'clientId' => $client->getKey(),
            'serviceId' => $service->getKey(),
            'specialistId' => $specialist->getKey(),
            'clientName' => $client->full_name,
            'serviceName' => $service->name,
            'specialistName' => $specialist->display_name,
            'contentSectionId' => $contentSection->getKey(),
            'contentSectionTitle' => $contentSection->title,
            'attachmentFilename' => $attachmentFilename,
        ], JSON_THROW_ON_ERROR);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-crm-${process.pid}`;
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

    return JSON.parse(output.trim().split('\n').at(-1) ?? '') as CrmFixture;
}

async function login(page: Page, fixture: CrmFixture): Promise<void> {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill(fixture.email);
    await page.locator('input[type="password"]').fill(fixture.password);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/admin(?:\/)?$/);
}

async function searchTableFor(page: Page, query: string): Promise<void> {
    const searchInput = page.getByRole('searchbox', {
        name: 'Поиск',
        exact: true,
    });

    await expect(searchInput).toBeVisible();
    await searchInput.fill(query);
    await expect(page.getByRole('row').filter({ hasText: query }).first()).toBeVisible();
}

async function assertBusinessField(page: Page, label: string, value: string): Promise<void> {
    const main = page.getByRole('main');

    const term = main
        .locator('dt, [role="term"]')
        .filter({ hasText: label });
    const definition = main
        .locator('dd, [role="definition"]')
        .filter({ hasText: value });

    await expect(term).toHaveCount(1);
    await expect(term).toBeVisible();
    await expect(term).toHaveText(label);
    await expect(definition).toHaveCount(1);
    await expect(definition).toBeVisible();
    await expect(definition).toHaveText(value);
}

test('staff can create a booking without technical inputs', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);

    await page.goto('/admin/bookings/create');
    await expect(page.getByRole('heading', { name: 'Создать Запись' })).toBeVisible();
    await expect(page.locator('input[name*="idempotency"], input[name*="timezone"], select[name*="meeting_link"]')).toHaveCount(0);

    await page.getByRole('combobox', { name: 'Клиент*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.clientName);
    await page.getByText(fixture.clientName, { exact: true }).click();
    await page.getByRole('combobox', { name: 'Услуга*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.serviceName);
    await page.getByText(fixture.serviceName, { exact: true }).click();
    await page.getByRole('combobox', { name: 'Специалист*', exact: true }).click();
    await page.getByText(fixture.specialistName, { exact: true }).click();
    await page.getByLabel('Дата и время').fill('2026-08-18T09:00');
    await page.getByLabel('Формат визита').selectOption('office');
    await page.getByRole('button', { name: 'Создать', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/bookings\/\d+$/);
    await expect(page.locator('.fi-in-text-item').filter({ hasText: fixture.clientName }).first()).toBeVisible();
    await expect(page.getByText(fixture.serviceName, { exact: true })).toBeVisible();
    await expect(page.getByText(/idempotency|event version|schedule timezone|client timezone/i)).toHaveCount(0);
});

test('staff sees business labels for client and content settings', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);

    await page.goto('/admin/clients');
    await expect(page.getByRole('heading', { name: 'Клиенты' })).toBeVisible();

    await searchTableFor(page, fixture.clientName);

    const clientsTimezoneCell = page
        .getByRole('row')
        .filter({ hasText: fixture.clientName })
        .getByRole('cell', { name: 'Всемирное время', exact: true });
    await expect(clientsTimezoneCell).toBeVisible();
    await expect(clientsTimezoneCell).toHaveText('Всемирное время');

    await page.goto(`/admin/clients/${fixture.clientId}`);
    await expect(page.locator('.fi-in-text-item').filter({ hasText: fixture.clientName }).first()).toBeVisible();

    await assertBusinessField(page, 'Часовой пояс', 'Всемирное время');

    await page.goto('/admin/content-sections');
    await expect(page.getByRole('heading', { name: 'Разделы контента' })).toBeVisible();

    await searchTableFor(page, fixture.contentSectionTitle);

    const contentSectionRow = page.getByRole('row').filter({ hasText: fixture.contentSectionTitle });
    await expect(contentSectionRow).toBeVisible();
    await expect(contentSectionRow.getByRole('cell', { name: 'Об академии', exact: true })).toBeVisible();
    await expect(contentSectionRow.getByRole('cell', { name: 'Русский', exact: true })).toBeVisible();

    await page.goto(`/admin/content-sections/${fixture.contentSectionId}`);
    await expect(page.locator('.fi-in-text-item').filter({ hasText: fixture.contentSectionTitle }).first()).toBeVisible();

    await assertBusinessField(page, 'Раздел', 'Об академии');
    await assertBusinessField(page, 'Язык', 'Русский');
});

test('staff can use the client cockpit for medical profile and private files', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);
    await page.goto('/admin/clients');
    await searchTableFor(page, fixture.clientName);
    await page.getByRole('row').filter({ hasText: fixture.clientName }).getByRole('link', { name: fixture.clientName, exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}$`));

    for (const tabLabel of ['Клинический профиль', 'Сеансы', 'Записи на приём', 'Опросы и отчёты', 'Файлы и МРТ']) {
        await expect(page.getByText(tabLabel, { exact: true }).last()).toBeVisible();
    }

    await page.getByRole('button', { name: 'Изменить медицинский профиль', exact: true }).click();
    const medicalDialog = page.getByRole('dialog', { name: 'Изменить медицинский профиль' });
    await expect(medicalDialog).toBeVisible();
    await medicalDialog.getByLabel('Анамнез').fill('Запись из клиентского рабочего места');
    await medicalDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(page.getByText('Запись из клиентского рабочего места', { exact: true })).toBeVisible();

    await page.getByText('Файлы и МРТ', { exact: true }).last().click();
    await page.getByRole('button', { name: 'Загрузить файл', exact: true }).click();
    const uploadDialog = page.getByRole('dialog', { name: 'Загрузить файл' });
    await expect(uploadDialog).toBeVisible();
    await uploadDialog.getByLabel('Тип файла').click();
    await uploadDialog.getByRole('option', { name: 'Медицинское заключение', exact: true }).click();
    await uploadDialog.locator('input[type="file"]').setInputFiles({
        name: 'ux-a-report.pdf',
        mimeType: 'application/pdf',
        buffer: Buffer.from('%PDF-1.4\nUX-A'),
    });
    await uploadDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(page.getByText('ux-a-report.pdf', { exact: true })).toBeVisible();
});

test('staff can create, view, and edit a client session from the CRM client flow', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);

    await page.goto('/admin/clients');
    await expect(page.getByRole('heading', { name: 'Клиенты' })).toBeVisible();
    await searchTableFor(page, fixture.clientName);

    const clientRow = page.getByRole('row').filter({ hasText: fixture.clientName });
    await clientRow.getByRole('link', { name: fixture.clientName, exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}$`));

    await page.goto(`/admin/clients/${fixture.clientId}/sessions`);
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`));
    await expect(page.getByRole('heading', { name: 'Сеансы клиента' })).toBeVisible();

    await page.getByRole('link', { name: 'Новый сеанс', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions/medical-sessions/create$`));

    await page.getByLabel('Дата и время сеанса').fill('2026-08-18T09:00');
    await page.getByLabel('Специалист').click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.specialistName);
    await page.getByText(`${fixture.specialistName} (активен)`, { exact: true }).click();
    await page.getByLabel('Боль').fill('Первичная запись о боли');
    await page.getByRole('button', { name: 'Создать', exact: true }).click();

    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`));
    const sessionRow = page.getByRole('row').filter({ hasText: '18.08.2026' });
    await expect(sessionRow).toBeVisible();
    await sessionRow.getByRole('link', { name: 'Открыть', exact: true }).click();
    await expect(page.getByText('Первичная запись о боли', { exact: true })).toBeVisible();
    await expect(page.getByText('Предыдущая запись о боли', { exact: true })).toBeVisible();
    await expect(page.getByText('Предыдущий подтверждённый результат', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Связать файл', exact: true }).click();
    await page.getByLabel('Файл клиента').click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.attachmentFilename);
    await page.getByText(new RegExp(fixture.attachmentFilename), { exact: false }).click();
    await page.getByRole('button', { name: 'Связать', exact: true }).click();
    await expect(page.getByText(fixture.attachmentFilename, { exact: true })).toBeVisible();
    await expect(page.getByText('Проверен', { exact: true })).toBeVisible();

    await page.getByRole('link', { name: 'Редактировать', exact: true }).click();
    await page.getByLabel('Боль').fill('Обновлённая запись о боли');
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`));
    await page.getByRole('row').filter({ hasText: '18.08.2026' }).getByRole('link', { name: 'Открыть', exact: true }).click();
    await expect(page.getByText('Обновлённая запись о боли', { exact: true })).toBeVisible();
    await expect(page.getByText(fixture.attachmentFilename, { exact: true })).toBeVisible();

    await page.getByRole('link', { name: 'К истории сеансов', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`));
});

test('crm sidebar navigation operates via SPA mode without full page reloads', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);

    // Set a marker on the window object to detect full document reloads
    await page.evaluate(() => {
        (window as Window & { __crm_spa_marker?: number }).__crm_spa_marker = 998877;
    });

    const openSidebarToggle = page
        .locator('.fi-topbar-open-sidebar-btn, [aria-controls="fi-main-sidebar"]')
        .first();

    const navigationSidebar = page.locator('#fi-main-sidebar');

    const navigateViaSidebar = async (linkName: string, expectedUrl: RegExp, expectedHeading: string): Promise<void> => {
        const targetLink = navigationSidebar.getByRole('link', { name: linkName, exact: true });

        if (await openSidebarToggle.isVisible()) {
            await openSidebarToggle.click();
        }

        await expect(targetLink).toBeVisible();
        await expect(targetLink).toBeEnabled();
        await targetLink.click();

        await expect(page).toHaveURL(expectedUrl);
        await expect(page.getByRole('heading', { name: expectedHeading })).toBeVisible();
    };

    // Navigate to Clients via sidebar link
    await navigateViaSidebar('Клиенты', /\/admin\/clients$/, 'Клиенты');

    // Verify window marker persists (no full page reload)
    const markerAfterClients = await page.evaluate(() => (window as Window & { __crm_spa_marker?: number }).__crm_spa_marker);
    expect(markerAfterClients).toBe(998877);

    // Navigate to Content Sections via sidebar link
    await navigateViaSidebar('Разделы контента', /\/admin\/content-sections$/, 'Разделы контента');

    const markerAfterContent = await page.evaluate(() => (window as Window & { __crm_spa_marker?: number }).__crm_spa_marker);
    expect(markerAfterContent).toBe(998877);

    // Navigate to Services via sidebar link
    await navigateViaSidebar('Услуги', /\/admin\/services$/, 'Услуги');

    const markerAfterServices = await page.evaluate(() => (window as Window & { __crm_spa_marker?: number }).__crm_spa_marker);
    expect(markerAfterServices).toBe(998877);
});
