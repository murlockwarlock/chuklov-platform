import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
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
    bookingStartsAt: string;
};

function validPdfBuffer(): Buffer {
    return Buffer.from([
        '%PDF-1.4',
        '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 595 842]>>endobj',
        'xref',
        '0 4',
        '0000000000 65535 f',
        '0000000009 00000 n',
        '0000000052 00000 n',
        '0000000101 00000 n',
        'trailer<</Size 4/Root 1 0 R>>',
        'startxref',
        '150',
        '%%EOF',
    ].join('\n'));
}

function createCrmFixture(): CrmFixture {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $suffix = \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(12));
        $email = 'playwright-crm-'.$suffix.'@example.test';
        $password = 'password';
        $admin = \\App\\Models\\User::factory()->forOrganization($organization)->create(['email' => $email]);
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::query()
            ->where('organization_id', $organization->getKey())
            ->update([
                'viewer_timezone' => null,
                'viewer_timezone_source' => 'organization',
                'viewer_timezone_suggestion' => null,
            ]);
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
        $organizationTimezone = $organization->defaultTimezone();
        $specialist = \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'CRM Специалист '.$suffix,
            'timezone' => $organizationTimezone,
        ]);
        $service = \\App\\Modules\\Services\\Domain\\Models\\Service::factory()->forOrganization($organization)->create([
            'name' => 'CRM Услуга '.$suffix,
            'formats' => ['office'],
        ]);
        $defaultLocation = \\App\\Modules\\Scheduling\\Domain\\Models\\WorkingLocation::query()
            ->where('organization_id', $organization->getKey())
            ->where('is_default_office', true)
            ->first();
        if ($defaultLocation === null) {
            $defaultLocation = \\App\\Modules\\Scheduling\\Domain\\Models\\WorkingLocation::factory()
                ->forOrganization($organization)
                ->defaultOffice()
                ->create([
                    'name' => 'Кабинет Алматы '.$suffix,
                    'address' => 'ул. Абая, 10',
                    'timezone' => 'UTC',
                ]);
        } else {
            $defaultLocation->update(['is_active' => true]);
        }
        \\App\\Modules\\Scheduling\\Domain\\Models\\WorkingLocation::query()
            ->where('organization_id', $organization->getKey())
            ->whereKeyNot($defaultLocation->getKey())
            ->update(['is_active' => false]);
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
        $leadTimeMinutes = (int) (\\App\\Modules\\Organizations\\Domain\\Models\\OrganizationSetting::query()
            ->where('organization_id', $organization->getKey())
            ->where('setting_key', 'booking_lead_time_minutes')
            ->value('integer_value') ?? 0);
        $minimumBookingStart = \\Carbon\\CarbonImmutable::now($organizationTimezone)->addMinutes($leadTimeMinutes + 60);
        $bookingStartsAt = $minimumBookingStart->startOfDay()->addDay()->setTime(9, 0);
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
            'bookingStartsAt' => $bookingStartsAt->format('Y-m-d').'T'.$bookingStartsAt->format('H:i'),
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

test.describe('specialist viewer timezone suggestion', () => {
    test.use({ timezoneId: 'Europe/Berlin' });

    test('specialist can reject a device timezone without being asked again', async ({ page }) => {
        const fixture = createCrmFixture();

        await login(page, fixture);
        await page.goto('/admin/scheduling-configuration');

        await expect(page.getByText('Время: Asia/Almaty', { exact: true })).toBeVisible();
        await expect(page.getByText('Мы определили ваш часовой пояс как Europe/Berlin.', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Оставить Asia/Almaty', exact: true }).click();
        await expect(page.getByText('Мы определили ваш часовой пояс как Europe/Berlin.', { exact: true })).not.toBeVisible();

        await page.reload();
        await expect(page.getByText('Время: Asia/Almaty', { exact: true })).toBeVisible();
        await expect(page.getByText('Мы определили ваш часовой пояс как Europe/Berlin.', { exact: true })).not.toBeVisible();
    });

    test('specialist can accept the device timezone without changing booking instants', async ({ page }) => {
        const fixture = createCrmFixture();

        await login(page, fixture);
        await page.goto('/admin/scheduling-configuration');

        await expect(page.getByRole('button', { name: 'Использовать Europe/Berlin', exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Использовать Europe/Berlin', exact: true }).click();
        await expect(page.getByText('Время: Europe/Berlin', { exact: true })).toBeVisible();
        await expect(page.getByText('Мы определили ваш часовой пояс как Europe/Berlin.', { exact: true })).not.toBeVisible();
    });
});

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
    await page.getByLabel('Дата и время').fill(fixture.bookingStartsAt);
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
    await expect(page.getByRole('heading', { name: 'База клиентов', exact: true })).toBeVisible();

    await searchTableFor(page, fixture.clientName);

    if ((page.viewportSize()?.width ?? 0) >= 1024) {
        const clientsTimezoneCell = page
            .getByRole('row')
            .filter({ hasText: fixture.clientName })
            .getByRole('cell', { name: 'Всемирное время', exact: true });
        await expect(clientsTimezoneCell).toBeVisible();
        await expect(clientsTimezoneCell).toHaveText('Всемирное время');
    }

    await page.goto(`/admin/clients/${fixture.clientId}`);
    await expect(page.getByRole('heading', { name: fixture.clientName, exact: true })).toBeVisible();

    await assertBusinessField(page, 'Часовой пояс', 'Всемирное время');

    await page.goto('/admin/content-sections');
    await expect(page.getByRole('heading', { name: 'Разделы контента' })).toBeVisible();

    await searchTableFor(page, fixture.contentSectionTitle);

    const contentSectionRow = page.getByRole('row').filter({ hasText: fixture.contentSectionTitle });
    await expect(contentSectionRow).toBeVisible();
    await expect(contentSectionRow.getByRole('cell', { name: 'Об академии', exact: true })).toBeVisible();
    await expect(contentSectionRow.getByRole('cell', { name: 'Русский', exact: true })).toBeVisible();

    await page.goto(`/admin/content-sections/${fixture.contentSectionId}`);
    await expect(page.getByRole('heading', { name: 'Раздел контента', exact: true })).toBeVisible();

    await assertBusinessField(page, 'Раздел', 'Об академии');
    await assertBusinessField(page, 'Язык', 'Русский');
    await assertBusinessField(page, 'Название', fixture.contentSectionTitle);
});

test('staff can use the client cockpit for medical profile and private files', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);
    await page.goto('/admin/clients');
    await searchTableFor(page, fixture.clientName);
    await page.getByRole('row').filter({ hasText: fixture.clientName }).getByRole('link', { name: fixture.clientName, exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}$`));

    for (const tabLabel of ['Клинический профиль', 'Сеансы', 'Записи на приём', 'Опросы', 'Файлы и МРТ']) {
        await expect(page.getByRole('tab', { name: tabLabel, exact: true })).toBeVisible();
    }

    await page.getByRole('button', { name: 'Изменить медицинский профиль', exact: true }).click();
    const medicalDialog = page.getByRole('dialog', { name: 'Изменить медицинский профиль' });
    const anamnesis = medicalDialog.getByRole('textbox', { name: 'Анамнез', exact: true });
    await expect(anamnesis).toBeVisible();
    await anamnesis.fill('Запись из клиентского рабочего места');
    await medicalDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(page.getByText('Запись из клиентского рабочего места', { exact: true })).toBeVisible();

    await page.getByRole('tab', { name: 'Файлы и МРТ', exact: true }).click();
    await page.getByRole('button', { name: 'Загрузить файл', exact: true }).click();
    const uploadDialog = page.getByRole('dialog', { name: 'Загрузить файл' });
    const attachmentType = uploadDialog.getByLabel('Тип файла');
    await expect(attachmentType).toBeVisible();
    await attachmentType.selectOption('medical_report');
    await expect(attachmentType).toHaveValue('medical_report');
    const uploadControl = uploadDialog
        .getByRole('group', { name: 'Файл*', exact: true })
        .locator('label')
        .filter({ hasText: 'Перетащите файлы или выберите', visible: true });
    await expect(uploadControl).toHaveCount(1);
    await expect(uploadControl).toBeVisible();
    const [fileChooser] = await Promise.all([
        page.waitForEvent('filechooser'),
        uploadControl.click(),
    ]);
    await fileChooser.setFiles({
        name: 'ux-a-report.pdf',
        mimeType: 'application/pdf',
        buffer: validPdfBuffer(),
    });
    const selectedFile = uploadDialog.getByRole('group', { name: 'ux-a-report.pdf', exact: true });
    await expect(selectedFile).toHaveCount(1);
    await expect(selectedFile).toBeVisible();
    const uploadSubmit = uploadDialog.getByRole('button', { name: 'Отправить', exact: true });
    await expect(uploadSubmit).toBeVisible();
    await expect(uploadSubmit).toBeEnabled();
    await expect(uploadDialog.getByText('Ошибка при загрузке', { exact: true })).toBeHidden();
    await uploadSubmit.click();
    await expect(uploadDialog).toBeHidden();

    const attachmentsTable = page.getByRole('table', { name: 'Файлы и МРТ' });
    const uploadedRow = attachmentsTable.getByRole('row').filter({ hasText: 'ux-a-report.pdf' });
    const uploadedFileCell = uploadedRow.getByRole('cell').filter({ hasText: 'ux-a-report.pdf' });
    await expect(attachmentsTable).toBeVisible();
    await expect(uploadedRow).toHaveCount(1);
    await expect(uploadedRow).toBeVisible();
    await expect(uploadedFileCell).toHaveCount(1);
    await expect(uploadedFileCell).toBeVisible();
    await expect(uploadedFileCell).toContainText('ux-a-report.pdf');
    const openAttachment = uploadedRow.getByRole('button', { name: 'Открыть', exact: true });
    await expect(openAttachment).toHaveCount(1);
    const [download, attachmentResponse] = await Promise.all([
        page.waitForEvent('download'),
        page.waitForResponse((response) => {
            const pathname = new URL(response.url()).pathname;

            return response.status() === 200 && /^\/admin\/attachments\/[^/]+$/.test(pathname);
        }),
        openAttachment.click(),
    ]);
    expect(download.suggestedFilename()).toBe('ux-a-report.pdf');
    expect(attachmentResponse.headers()['content-type']).toContain('application/pdf');
    expect(attachmentResponse.headers()['content-disposition']).toContain('ux-a-report.pdf');
    const downloadPath = await download.path();
    expect(downloadPath).not.toBeNull();
    if (downloadPath === null) {
        throw new Error('The authorized attachment download did not produce a file.');
    }
    expect(readFileSync(downloadPath)).toEqual(validPdfBuffer());
});

test('staff can create, view, and edit a client session from the CRM client flow', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);

    await page.goto('/admin/clients');
    await expect(page.getByRole('heading', { name: 'База клиентов', exact: true })).toBeVisible();
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
    await navigateViaSidebar('База клиентов', /\/admin\/clients$/, 'База клиентов');

    // Verify window marker persists (no full page reload)
    const markerAfterClients = await page.evaluate(() => (window as Window & { __crm_spa_marker?: number }).__crm_spa_marker);
    expect(markerAfterClients).toBe(998877);

    // Navigate to Content Sections via sidebar link
    await navigateViaSidebar('Разделы контента', /\/admin\/content-sections$/, 'Разделы контента');

    const markerAfterContent = await page.evaluate(() => (window as Window & { __crm_spa_marker?: number }).__crm_spa_marker);
    expect(markerAfterContent).toBe(998877);

    // Navigate to Services via sidebar link
    await navigateViaSidebar('Каталог услуг', /\/admin\/services$/, 'Каталог услуг');

    const markerAfterServices = await page.evaluate(() => (window as Window & { __crm_spa_marker?: number }).__crm_spa_marker);
    expect(markerAfterServices).toBe(998877);
});
