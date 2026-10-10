import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { expect, test, type Locator, type Page } from '@playwright/test';

type CommunitiesFixture = {
    email: string;
    password: string;
    contentSectionId: number;
    title: string;
};

type TelegramDelivery = {
    outcome: string;
    persistedBody: string;
    requests: Array<Record<string, unknown>>;
};

function telegramHasLinkedText(delivery: TelegramDelivery, url: string, label: string): boolean {
    const linkPattern = new RegExp(
        `<a href="${escapeRegExp(url)}">(?:<[^>]+>)*${escapeRegExp(label)}(?:</[^>]+>)*</a>`,
    );

    return delivery.requests.some((payload) => linkPattern.test(String(payload.text ?? '')));
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function createCommunitiesFixture(): CommunitiesFixture {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $suffix = \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(12));
        $email = 'playwright-communities-'.$suffix.'@example.test';
        $password = 'password';
        $admin = \\App\\Models\\User::factory()->forOrganization($organization)->create(['email' => $email]);
        \\Illuminate\\Support\\Facades\\RateLimiter::clear('livewire-rate-limiter:'.sha1('Filament\\\\Auth\\\\Pages\\\\Login|authenticate|127.0.0.1'));
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        $section = \\App\\Modules\\Content\\Domain\\Models\\ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'communities',
            'locale' => 'ru',
            'title' => 'Сообщества '.$suffix,
            'body' => '',
            'delivery_mode' => 'both',
            'is_visible' => true,
        ]);
        echo json_encode(['email' => $email, 'password' => $password, 'contentSectionId' => $section->getKey(), 'title' => $section->title], JSON_THROW_ON_ERROR);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-communities-${process.pid}`;
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

    return JSON.parse(output.trim().split('\n').at(-1) ?? '') as CommunitiesFixture;
}

function deliverCommunitiesThroughTelegram(contentSectionId: number): TelegramDelivery {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        config()->set('nutgram.token', \\SergiX44\\Nutgram\\Testing\\FakeNutgram::TOKEN);
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        $bot = \\SergiX44\\Nutgram\\Nutgram::fake();
        $section = \\App\\Modules\\Content\\Domain\\Models\\ContentSection::query()->findOrFail(${contentSectionId});
        app()->instance(
            \\App\\Modules\\Channels\\Application\\NotificationChannelRegistry::class,
            new \\App\\Modules\\Channels\\Application\\NotificationChannelRegistry([
                new \\App\\Modules\\Channels\\Infrastructure\\Telegram\\TelegramNotificationChannel($bot),
            ]),
        );
        $result = app(\\App\\Modules\\Channels\\Application\\SendTelegramContentSection::class)->handle(
            new \\App\\Modules\\Identity\\Application\\VerifiedChannelIdentity('telegram', 'communities-chat', 'Communities client', 'ru'),
            'communities',
            'ru',
        );
        $requests = [];
        foreach ($bot->getRequestHistory() as $historyEntry) {
            $request = array_values($historyEntry)[0] ?? null;
            if (! $request instanceof \\Psr\\Http\\Message\\RequestInterface) {
                continue;
            }
            $payload = json_decode((string) $request->getBody(), true);
            if (is_array($payload)) {
                $requests[] = $payload;
            }
        }
        echo json_encode([
            'outcome' => $result->outcome->value,
            'persistedBody' => $section->body,
            'requests' => array_values(array_filter(
                $requests,
                static fn (array $payload): bool => str_contains(
                    (string) ($payload['text'] ?? ''),
                    '<b>'.htmlspecialchars($section->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>',
                ),
            )),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-telegram-${process.pid}`;
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

    return JSON.parse(output.trim().split('\n').at(-1) ?? '') as TelegramDelivery;
}

async function login(page: Page, fixture: CommunitiesFixture): Promise<void> {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill(fixture.email);
    await page.locator('input[type="password"]').fill(fixture.password);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/admin(?:\/)?$/);
}

async function selectText(page: Page, editor: Locator, value: string): Promise<void> {
    await editor.click({ force: true });
    await editor.press('ControlOrMeta+A');
    await editor.press('ArrowLeft');

    for (let index = 0; index < value.length; index += 1) {
        await editor.press('Shift+ArrowRight');
    }

    await expect.poll(() => editor.evaluate(() => window.getSelection()?.toString() ?? '')).toBe(value);
}

async function waitForEditorReady(editor: Locator): Promise<void> {
    await expect.poll(() => editor.evaluate((element) => {
        const root = element.closest('[x-data]');
        const alpine = (window as typeof window & {
            Alpine?: {
                $data: (element: Element) => { getEditor?: () => unknown };
            };
        }).Alpine;

        return root !== null && alpine?.$data(root)?.getEditor?.() !== undefined;
    })).toBe(true);
}

async function applyLink(page: Page, editor: Locator, url: string): Promise<void> {
    const linkButton = page.locator('button[aria-label="Ссылка"]:visible').first();
    const selectionLength = await editor.evaluate(() => window.getSelection()?.toString().length ?? 0);
    const dialog = page.locator('[role="dialog"] .fi-modal-window').filter({ hasText: 'Открывать в новой вкладке' }).last();
    await page.waitForTimeout(300);
    console.log(`rich-editor-link-button ${JSON.stringify(await linkButton.evaluate((element) => ({
        click: element.getAttribute('x-on:click'),
        disabled: (element as HTMLButtonElement).disabled,
        pressed: element.getAttribute('aria-pressed'),
    })))}`);
    const triggerLinkButton = async (): Promise<void> => {
        if ((page.viewportSize()?.width ?? 1024) < 600) {
            await linkButton.tap({ force: true });

            return;
        }

        await linkButton.click({ force: true });
    };

    for (let attempt = 0; attempt < 2; attempt += 1) {
        await linkButton.scrollIntoViewIfNeeded();
        await triggerLinkButton();

        try {
            await expect(dialog).toBeVisible({ timeout: 5_000 });
            break;
        } catch (error) {
            if (attempt === 1) {
                throw error;
            }

            await editor.focus();
            await editor.press('ArrowLeft');

            for (let index = 0; index < selectionLength; index += 1) {
                await editor.press('Shift+ArrowRight');
            }

            await page.waitForTimeout(500);
        }
    }

    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading', { name: 'Ссылка', exact: true })).toBeVisible();
    await dialog.getByRole('textbox', { name: 'URL', exact: true }).fill(url);
    await dialog.getByRole('button', { name: 'Отправить', exact: true }).click({ force: true });
    await expect(dialog).toBeHidden();
    await expect(editor.locator(`a[href="${url}"]`)).toHaveCount(1);
    await page.waitForTimeout(500);
}

async function saveContentSection(page: Page): Promise<void> {
    const saveButton = page.getByRole('button', { name: 'Сохранить', exact: true });
    await expect(saveButton).toBeEnabled();
    await saveButton.scrollIntoViewIfNeeded();
    await saveButton.click({ force: true });
    await expect(page.getByRole('heading', { name: 'Сохранено', exact: true })).toBeVisible({ timeout: 15_000 });
}

test('owner-created Communities RichEditor links survive the real CRM flow', async ({ page }) => {
    test.setTimeout(60_000);

    const fixture = createCommunitiesFixture();
    const communityText = 'Закрытое сообщество';
    const initialUrl = 'https://t.me/test_community';
    const updatedUrl = 'https://example.test/community-updated';

    await login(page, fixture);
    await page.goto(`/admin/content-sections/${fixture.contentSectionId}/edit`, { waitUntil: 'domcontentloaded' });

    const editor = page.locator('.fi-fo-rich-editor-content [contenteditable=true], .fi-fo-rich-editor-content[contenteditable=true]').first();
    await waitForEditorReady(editor);
    await editor.click();
    await editor.pressSequentially(`${communityText} 😀`);
    await expect(editor).toContainText(`${communityText} 😀`);

    await selectText(page, editor, communityText);
    await applyLink(page, editor, initialUrl);
    await expect(editor.locator('a').filter({ hasText: communityText })).toHaveAttribute('href', initialUrl);
    await page.waitForTimeout(500);

    await selectText(page, editor, communityText);
    await page.locator('button[aria-label="Подчеркнутый"]').click();
    await expect(editor.locator('u').filter({ hasText: communityText })).toHaveCount(1);
    await page.waitForTimeout(500);

    await saveContentSection(page);
    await page.goto(`/admin/content-sections/${fixture.contentSectionId}/edit`, { waitUntil: 'domcontentloaded' });

    const reloadedEditor = page.locator('.fi-fo-rich-editor-content [contenteditable=true], .fi-fo-rich-editor-content[contenteditable=true]').first();
    await waitForEditorReady(reloadedEditor);
    await expect(reloadedEditor.locator(`a[href="${initialUrl}"]`)).toHaveCount(1);
    await expect(reloadedEditor.locator('a').filter({ hasText: communityText })).toHaveAttribute('href', initialUrl);
    await expect(reloadedEditor.locator('u').filter({ hasText: communityText })).toHaveCount(1);
    await expect(reloadedEditor).toContainText('😀');

    await page.goto('/portal/sections/communities', { waitUntil: 'domcontentloaded' });
    const portalSection = page.locator('article').filter({ hasText: fixture.title }).first();
    const portalLink = portalSection.locator(`a[href="${initialUrl}"]`);
    await expect(portalLink).toHaveCount(1);
    await expect(portalLink).toHaveText(communityText);

    const initialTelegram = deliverCommunitiesThroughTelegram(fixture.contentSectionId);
    expect(initialTelegram.outcome).toBe('delivered');
    expect(initialTelegram.persistedBody).toContain(`href="${initialUrl}"`);
    expect(telegramHasLinkedText(initialTelegram, initialUrl, communityText)).toBe(true);

    await page.goto(`/admin/content-sections/${fixture.contentSectionId}/edit`, { waitUntil: 'domcontentloaded' });
    const previewEditor = page.locator('.fi-fo-rich-editor-content [contenteditable=true], .fi-fo-rich-editor-content[contenteditable=true]').first();
    await waitForEditorReady(previewEditor);
    await expect(previewEditor.locator(`a[href="${initialUrl}"]`)).toHaveCount(1);
    const previewButton = page.getByRole('button', { name: 'Предпросмотр Telegram', exact: true });
    await expect(previewButton).toBeEnabled();
    await previewButton.scrollIntoViewIfNeeded();
    await previewButton.click();
    const previewDialog = page.getByRole('dialog', { name: 'Предпросмотр Telegram' });
    await expect(previewDialog.locator(`a[href="${initialUrl}"]`)).toHaveText(communityText);
    await expect(previewDialog.getByText('Открыть полностью', { exact: true })).toBeVisible();
    await expect(previewDialog.locator('a')).toHaveCount(2);
    const closePreviewResponse = page.waitForResponse((response) => response.url().includes('/livewire-')
        && response.request().method() === 'POST');
    await previewDialog.locator('button.fi-modal-close-btn').click({ force: true });
    await expect(previewDialog).toBeHidden();
    await expect((await closePreviewResponse).status()).toBe(200);
    await expect(page.locator('[role="dialog"].fi-modal-open')).toHaveCount(0);
    await page.waitForTimeout(1000);

    await page.reload({ waitUntil: 'networkidle' });
    const replacementEditor = page.locator('.fi-fo-rich-editor-content [contenteditable=true], .fi-fo-rich-editor-content[contenteditable=true]').first();
    await waitForEditorReady(replacementEditor);
    await expect(replacementEditor.locator(`a[href="${initialUrl}"]`)).toHaveCount(1);
    await selectText(page, replacementEditor, communityText);
    await applyLink(page, replacementEditor, updatedUrl);
    await saveContentSection(page);
    await page.goto(`/admin/content-sections/${fixture.contentSectionId}/edit`, { waitUntil: 'domcontentloaded' });

    const finalEditor = page.locator('.fi-fo-rich-editor-content').first();
    await expect(finalEditor.locator(`a[href="${updatedUrl}"]`)).toHaveCount(1);
    await expect(finalEditor.locator(`a[href="${initialUrl}"]`)).toHaveCount(0);

    await page.goto('/portal/sections/communities', { waitUntil: 'domcontentloaded' });
    const updatedPortalSection = page.locator('article').filter({ hasText: fixture.title }).first();
    await expect(updatedPortalSection.locator(`a[href="${updatedUrl}"]`)).toHaveText(communityText);
    await expect(updatedPortalSection.locator(`a[href="${initialUrl}"]`)).toHaveCount(0);

    const updatedTelegram = deliverCommunitiesThroughTelegram(fixture.contentSectionId);
    expect(updatedTelegram.outcome).toBe('delivered');
    expect(updatedTelegram.persistedBody).not.toContain(`href="${initialUrl}"`);
    expect(updatedTelegram.persistedBody).toContain(`href="${updatedUrl}"`);
    expect(telegramHasLinkedText(updatedTelegram, updatedUrl, communityText)).toBe(true);
    expect(updatedTelegram.requests.some((payload) => String(payload.text ?? '').includes(`<a href="${initialUrl}">`))).toBe(false);

    await page.goto(`/admin/content-sections/${fixture.contentSectionId}/edit`, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => undefined);
    const updatedPreviewEditor = page.locator('.fi-fo-rich-editor-content').first();
    await expect(updatedPreviewEditor.locator(`a[href="${updatedUrl}"]`)).toHaveCount(1);
    const updatedPreviewButton = page.getByRole('button', { name: 'Предпросмотр Telegram', exact: true });
    await expect(updatedPreviewButton).toBeEnabled({ timeout: 15_000 });
    const previewResponse = page.waitForResponse((response) => response.url().includes('/livewire-')
        && response.request().method() === 'POST');
    await updatedPreviewButton.click();
    await expect((await previewResponse).status()).toBe(200);
    const updatedPreviewDialog = page.getByRole('dialog', { name: 'Предпросмотр Telegram' });
    const updatedPreviewWindow = updatedPreviewDialog.locator('.fi-modal-window');
    await expect(updatedPreviewWindow).toBeVisible({ timeout: 10_000 });
    const previewBounds = await updatedPreviewWindow.boundingBox();
    const viewport = page.viewportSize();
    expect(previewBounds).not.toBeNull();
    expect(viewport).not.toBeNull();
    if (previewBounds !== null && viewport !== null) {
        expect(previewBounds.x).toBeGreaterThanOrEqual(0);
        expect(previewBounds.x + previewBounds.width).toBeLessThanOrEqual(viewport.width);
    }
    await expect(updatedPreviewWindow.locator(`a[href="${updatedUrl}"]`)).toHaveText(communityText);
    await expect(updatedPreviewWindow.locator(`a[href="${initialUrl}"]`)).toHaveCount(0);
    await expect(updatedPreviewWindow.getByText('Открыть полностью', { exact: true })).toBeVisible();
});
