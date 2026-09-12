import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';

type Manifest = Record<string, { file: string }>;

const manifest = JSON.parse(readFileSync(resolve('public/build/manifest.json'), 'utf8')) as Manifest;
const soundScript = resolve('public/build', manifest['resources/js/filament/database-notification-sound.ts'].file);

function notificationMarkup(id: string, statusClass: string): string {
    return `<div role="listitem" class="fi-no-notification-unread-ctn" wire:key="${id}.database-notifications.ctn"><div class="fi-no-notification fi-inline ${statusClass}"></div></div>`;
}

test('CRM notification sound follows the Filament database notification DOM contract', async ({ page }) => {
    const initialNotificationId = '11111111-1111-4111-8111-111111111111';
    const newNotificationId = '22222222-2222-4222-8222-222222222222';
    const infoNotificationId = '33333333-3333-4333-8333-333333333333';

    await page.addInitScript(() => {
        type SoundWindow = Window & { notificationSoundCount: number };

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
                gain: { setValueAtTime: () => void; exponentialRampToValueAtTime: () => void };
                connect: () => void;
            } {
                return {
                    gain: {
                        setValueAtTime: () => undefined,
                        exponentialRampToValueAtTime: () => undefined,
                    },
                    connect: () => undefined,
                };
            }
        }

        (window as SoundWindow).notificationSoundCount = 0;
        Object.defineProperty(window, 'AudioContext', {
            configurable: true,
            value: FakeAudioContext,
        });
    });

    await page.setContent(`
        <div class="fi-no-database"></div>
        <div id="database-notifications">
            ${notificationMarkup(initialNotificationId, 'fi-status-danger')}
        </div>
    `);
    await page.addScriptTag({ path: soundScript, type: 'module' });
    await page.waitForTimeout(50);

    await expect.poll(() => page.evaluate(() => (window as Window & { notificationSoundCount: number }).notificationSoundCount)).toBe(0);

    await page.locator('body').dispatchEvent('pointerdown');
    await page.locator('#database-notifications').evaluate((root, markup) => root.insertAdjacentHTML('beforeend', markup), notificationMarkup(newNotificationId, 'fi-status-warning'));
    await expect.poll(() => page.evaluate(() => (window as Window & { notificationSoundCount: number }).notificationSoundCount)).toBe(1);

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
});
