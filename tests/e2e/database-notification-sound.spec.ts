import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test, type Page } from '@playwright/test';

type Manifest = Record<string, { file: string }>;

const manifest = JSON.parse(readFileSync(resolve('public/build/manifest.json'), 'utf8')) as Manifest;
const soundScript = resolve('public/build', manifest['resources/js/filament/database-notification-sound.ts'].file);

function notificationMarkup(id: string, statusClass: string, unread = true): string {
    const containerClass = unread ? 'fi-no-notification-unread-ctn' : 'fi-no-notification-read-ctn';

    return `<div role="listitem" class="${containerClass}" wire:key="${id}.database-notifications.ctn"><div class="fi-no-notification fi-inline ${statusClass}"></div></div>`;
}

type NotificationFixture = {
    email: string;
    password: string;
};

function createNotificationFixture(): NotificationFixture {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $suffix = \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(12));
        $email = 'playwright-notifications-'.$suffix.'@example.test';
        $password = 'password';
        $admin = \\App\\Models\\User::factory()->forOrganization($organization)->create(['email' => $email]);
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        $channel = app(\\App\\Modules\\Channels\\Infrastructure\\Database\\DatabaseNotificationChannel::class);
        $action = new \\App\\Modules\\Channels\\Domain\\ValueObjects\\NotificationActionButton('Открыть', url('/admin'));
        for ($index = 0; $index < 51; $index++) {
            $channel->send(new \\App\\Modules\\Channels\\Domain\\ValueObjects\\NotificationMessage(
                recipientExternalId: (string) $admin->getKey(),
                body: 'Тестовое уведомление '.$index,
                subject: 'Тестовое уведомление '.$index,
                locale: 'ru',
                idempotencyKey: 'playwright-notifications-'.$suffix.'-'.$index,
                actionButton: $action,
                organizationId: $organization->getKey(),
                severity: \\App\\Modules\\Channels\\Domain\\Enums\\NotificationSeverity::Action,
            ));
        }
        echo json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-notifications-${process.pid}`;
    mkdirSync(psyshConfigDirectory, { recursive: true });
    const output = execFileSync('php', ['artisan', 'tinker', '--execute', php], {
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

    return JSON.parse(output.trim().split('\n').at(-1) ?? '') as NotificationFixture;
}

async function login(page: Page, fixture: NotificationFixture): Promise<void> {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill(fixture.email);
    await page.locator('input[type="password"]').fill(fixture.password);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/admin(?:\/)?$/);
}

test('CRM notification sound follows the Filament database notification DOM contract', async ({ page }) => {
    const initialNotificationId = '11111111-1111-4111-8111-111111111111';
    const newNotificationId = '22222222-2222-4222-8222-222222222222';
    const infoNotificationId = '33333333-3333-4333-8333-333333333333';

    await page.addInitScript(() => {
        type SoundWindow = Window & { notificationSoundCount: number; notificationPeakGain: number };

        class FakeAudioContext {
            public currentTime = 0;
            public destination = {};

            public resume(): Promise<void> {
                return Promise.resolve();
            }

            public createOscillator(): {
                type: string;
                frequency: { setValueAtTime: () => void; exponentialRampToValueAtTime: () => void };
                connect: () => void;
                start: () => void;
                stop: () => void;
            } {
                const target = window as SoundWindow;

                return {
                    type: 'sine',
                    frequency: {
                        setValueAtTime: () => undefined,
                        exponentialRampToValueAtTime: () => undefined,
                    },
                    connect: () => undefined,
                    start: () => {
                        target.notificationSoundCount += 1;
                    },
                    stop: () => undefined,
                };
            }

            public createGain(): {
                gain: { setValueAtTime: (value: number) => void; exponentialRampToValueAtTime: () => void };
                connect: () => void;
            } {
                const target = window as SoundWindow;

                return {
                    gain: {
                        setValueAtTime: (value: number) => {
                            target.notificationPeakGain = value;
                        },
                        exponentialRampToValueAtTime: () => undefined,
                    },
                    connect: () => undefined,
                };
            }
        }

        (window as SoundWindow).notificationSoundCount = 0;
        (window as SoundWindow).notificationPeakGain = 0;
        Object.defineProperty(window, 'AudioContext', {
            configurable: true,
            value: FakeAudioContext,
        });
    });

    await page.goto('about:blank');
    await page.setContent(`
        <title>CRM</title>
        <div class="fi-no-database"></div>
        <div id="database-notifications">
            ${notificationMarkup(initialNotificationId, 'fi-status-danger', false)}
        </div>
    `);
    await page.addScriptTag({ path: soundScript, type: 'module' });
    await page.waitForTimeout(50);

    await expect.poll(() => page.evaluate(() => (window as Window & { notificationSoundCount: number }).notificationSoundCount)).toBe(0);

    await page.locator('body').dispatchEvent('pointerdown');
    await page.locator('#database-notifications').evaluate((root, markup) => root.insertAdjacentHTML('beforeend', markup), notificationMarkup(newNotificationId, 'fi-status-warning'));
    await expect.poll(() => page.evaluate(() => (window as Window & { notificationSoundCount: number }).notificationSoundCount)).toBe(1);
    await expect.poll(() => page.evaluate(() => (window as Window & { notificationPeakGain: number }).notificationPeakGain)).toBeGreaterThan(0.05);

    await page.locator('#database-notifications').evaluate((root) => {
        const entries = root.querySelectorAll<HTMLElement>('.fi-no-notification-unread-ctn');
        const entry = entries.item(entries.length - 1);
        entry?.setAttribute('wire:key', entry.getAttribute('wire:key') ?? '');
    });
    await page.waitForTimeout(50);
    await expect.poll(() => page.evaluate(() => (window as Window & { notificationSoundCount: number }).notificationSoundCount)).toBe(1);

    await page.locator('#database-notifications').evaluate((root, markup) => root.insertAdjacentHTML('beforeend', markup), notificationMarkup(infoNotificationId, 'fi-status-success'));
    await page.waitForTimeout(50);
    await expect.poll(() => page.evaluate(() => (window as Window & { notificationSoundCount: number }).notificationSoundCount)).toBe(1);

    await page.evaluate(() => window.dispatchEvent(new Event('livewire:navigated')));
    await page.waitForTimeout(50);
    await page.locator('#database-notifications').evaluate((root) => root.setAttribute('class', root.className));
    await page.waitForTimeout(50);
    await expect.poll(() => page.evaluate(() => (window as Window & { notificationSoundCount: number }).notificationSoundCount)).toBe(1);
    await page.locator('#database-notifications').evaluate((root) => root.querySelectorAll('.fi-no-notification-unread-ctn').forEach((entry) => entry.remove()));
});

test('CRM title unread counter follows the authoritative total across pagination and read actions', async ({ page }) => {
    const fixture = createNotificationFixture();
    await login(page, fixture);

    const baseTitle = (await page.title()).replace(/^\(\d+\)\s+/, '');
    await expect.poll(() => page.title(), { timeout: 15_000 }).toBe(`(51) ${baseTitle}`);
    const titleWithUnread = await page.title();

    const notificationTrigger = page.locator('.fi-no-database .fi-modal-trigger .fi-topbar-database-notifications-btn:visible').last();
    await expect(notificationTrigger).toBeVisible();
    await expect(notificationTrigger).toBeEnabled();
    await page.evaluate(() => {
        const target = window as Window & { openModalDispatchCount?: number };
        const originalDispatchEvent = window.dispatchEvent.bind(window);
        target.openModalDispatchCount = 0;
        window.dispatchEvent = (event: Event): boolean => {
            if (event.type === 'open-modal') {
                target.openModalDispatchCount = (target.openModalDispatchCount ?? 0) + 1;
            }

            return originalDispatchEvent(event);
        };
    });
    await notificationTrigger.click();
    console.log('open modal dispatch count', await page.evaluate(() => (window as Window & { openModalDispatchCount?: number }).openModalDispatchCount));
    console.log('notification modal state', await page.evaluate(() => {
        const element = document.querySelector('#database-notifications') as (HTMLElement & { _x_dataStack?: Array<{ isOpen?: boolean; open?: () => void }> }) | null;
        const data = element?._x_dataStack?.[0];

        return {
            stackLength: element?._x_dataStack?.length ?? 0,
            isOpen: data?.isOpen ?? null,
            hasOpen: typeof data?.open === 'function',
            display: element === null ? null : window.getComputedStyle(element).display,
        };
    }));
    const notificationCenter = page.locator('#database-notifications').last();
    await expect(notificationCenter).toBeVisible();
    const nextPage = notificationCenter.locator('.fi-pagination-next-btn');
    await expect(nextPage).toBeVisible();
    await nextPage.click();
    await expect.poll(() => page.title()).toBe(titleWithUnread);
    await notificationCenter.locator('.fi-pagination-previous-btn').click();
    await expect.poll(() => page.title()).toBe(titleWithUnread);

    await notificationCenter.getByRole('link', { name: 'Открыть', exact: true }).first().click();
    await expect.poll(() => page.title(), { timeout: 15_000 }).toBe(`(50) ${baseTitle}`);

    await notificationTrigger.click();
    const reopenedCenter = page.locator('#database-notifications').last();
    await expect(reopenedCenter).toBeVisible();
    await reopenedCenter.getByRole('button', { name: /прочитан/i }).click();
    await expect.poll(() => page.title(), { timeout: 15_000 }).toBe(baseTitle);

    await page.goto('/admin/clients');
    await expect.poll(() => page.title(), { timeout: 15_000 }).not.toMatch(/^\(\d+\)/);
});
