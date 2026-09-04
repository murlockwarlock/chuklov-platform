import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { expect, test, type Locator, type Page } from '@playwright/test';

type CommunitiesFixture = {
    email: string;
    password: string;
    contentSectionId: number;
};

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
            'body' => 'Закрытое сообщество 😀',
            'delivery_mode' => 'both',
            'is_visible' => true,
        ]);
        echo json_encode(['email' => $email, 'password' => $password, 'contentSectionId' => $section->getKey()], JSON_THROW_ON_ERROR);
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

async function login(page: Page, fixture: CommunitiesFixture): Promise<void> {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill(fixture.email);
    await page.locator('input[type="password"]').fill(fixture.password);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/admin(?:\/)?$/);
}

async function selectText(editor: Locator, value: string): Promise<void> {
    await editor.evaluate((element, text) => {
        const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode();

        while (node !== null) {
            const content = node.textContent ?? '';
            const start = content.indexOf(text);

            if (start !== -1) {
                const range = document.createRange();
                range.setStart(node, start);
                range.setEnd(node, start + text.length);
                const selection = window.getSelection();
                selection?.removeAllRanges();
                selection?.addRange(range);
                (element as HTMLElement).focus();
                document.dispatchEvent(new Event('selectionchange', { bubbles: true }));

                return;
            }

            node = walker.nextNode();
        }

        throw new Error(`Text not found in editor: ${text}`);
    }, value);
}

async function applyLink(page: Page, editor: Locator, url: string): Promise<void> {
    await page.locator('button[aria-label="Ссылка"]').click();
    const dialog = page.getByRole('dialog', { name: 'Ссылка' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('textbox', { name: 'URL', exact: true }).fill(url);
    await dialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(editor.locator(`a[href="${url}"]`)).toHaveCount(1);
}

async function saveContentSection(page: Page, contentSectionId: number): Promise<void> {
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/content-sections/${contentSectionId}$`));
}

test('owner-created Communities RichEditor links survive the real CRM flow', async ({ page }) => {
    const fixture = createCommunitiesFixture();
    const communityText = 'Закрытое сообщество';
    const initialUrl = 'https://t.me/test_community';
    const updatedUrl = 'https://example.test/community-updated';

    await login(page, fixture);
    await page.goto(`/admin/content-sections/${fixture.contentSectionId}/edit`);

    const editor = page.locator('.fi-fo-rich-editor-content').first();
    await expect(editor).toContainText(`${communityText} 😀`);

    await editor.click();
    await editor.press('ControlOrMeta+A');
    await page.locator('button[aria-label="Подчеркнутый"]').click();
    await expect(editor.locator('u')).toHaveCount(1);
    await selectText(editor, communityText);
    await applyLink(page, editor, initialUrl);
    await expect(editor.locator('a').filter({ hasText: communityText })).toHaveAttribute('href', initialUrl);

    await saveContentSection(page, fixture.contentSectionId);
    await page.goto(`/admin/content-sections/${fixture.contentSectionId}/edit`);

    const reloadedEditor = page.locator('.fi-fo-rich-editor-content').first();
    await expect(reloadedEditor.locator(`a[href="${initialUrl}"]`)).toHaveCount(1);
    await expect(reloadedEditor.locator('a').filter({ hasText: communityText })).toHaveAttribute('href', initialUrl);
    await expect(reloadedEditor.locator('u')).toHaveCount(1);
    await expect(reloadedEditor).toContainText('😀');

    await page.goto('/portal/sections/communities');
    const portalLink = page.locator(`a[href="${initialUrl}"]`);
    await expect(portalLink).toHaveCount(1);
    await expect(portalLink).toHaveText(communityText);

    await page.goto(`/admin/content-sections/${fixture.contentSectionId}/edit`);
    const previewEditor = page.locator('.fi-fo-rich-editor-content').first();
    await page.getByRole('button', { name: 'Предпросмотр Telegram', exact: true }).click();
    const previewDialog = page.getByRole('dialog', { name: 'Предпросмотр Telegram' });
    await expect(previewDialog.locator(`a[href="${initialUrl}"]`)).toHaveText(communityText);
    await expect(previewDialog.getByText('Открыть полностью', { exact: true })).toBeVisible();
    await expect(previewDialog.locator('a')).toHaveCount(2);
    await previewDialog.getByRole('button', { name: 'Закрыть', exact: true }).click();
    await expect(previewDialog).toBeHidden();

    await selectText(previewEditor, communityText);
    await applyLink(page, previewEditor, updatedUrl);
    await saveContentSection(page, fixture.contentSectionId);
    await page.goto(`/admin/content-sections/${fixture.contentSectionId}/edit`);

    const finalEditor = page.locator('.fi-fo-rich-editor-content').first();
    await expect(finalEditor.locator(`a[href="${updatedUrl}"]`)).toHaveCount(1);
    await expect(finalEditor.locator(`a[href="${initialUrl}"]`)).toHaveCount(0);

    await page.goto('/portal/sections/communities');
    await expect(page.locator(`a[href="${updatedUrl}"]`)).toHaveText(communityText);
    await expect(page.locator(`a[href="${initialUrl}"]`)).toHaveCount(0);

    await page.goto(`/admin/content-sections/${fixture.contentSectionId}/edit`);
    await page.getByRole('button', { name: 'Предпросмотр Telegram', exact: true }).click();
    const updatedPreviewDialog = page.getByRole('dialog', { name: 'Предпросмотр Telegram' });
    await expect(updatedPreviewDialog.locator(`a[href="${updatedUrl}"]`)).toHaveText(communityText);
    await expect(updatedPreviewDialog.locator(`a[href="${initialUrl}"]`)).toHaveCount(0);
    await expect(updatedPreviewDialog.getByText('Открыть полностью', { exact: true })).toBeVisible();
});
