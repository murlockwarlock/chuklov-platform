const seenNotificationIds = new Set<string>();
let notificationListInitialized = false;
let scanQueued = false;
let audioContext: AudioContext | null = null;
let baseDocumentTitle = '';

const notificationIdPattern = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i;

function armAudio(): void {
    if (audioContext === null && typeof window.AudioContext === 'function') {
        try {
            audioContext = new window.AudioContext();
        } catch {
            return;
        }
    }

    if (audioContext !== null) {
        void audioContext.resume().catch(() => undefined);
    }
}

function playNotificationSound(): void {
    if (audioContext === null) {
        return;
    }

    try {
        const now = audioContext.currentTime;
        const oscillator = audioContext.createOscillator();
        const gain = audioContext.createGain();

        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, now);
        oscillator.frequency.exponentialRampToValueAtTime(660, now + 0.12);
        gain.gain.setValueAtTime(0.12, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.16);
        oscillator.connect(gain);
        gain.connect(audioContext.destination);
        oscillator.start(now);
        oscillator.stop(now + 0.12);
    } catch {
        return;
    }
}

function notificationId(element: Element): string | null {
    const key = element.getAttribute('wire:key') ?? '';
    return key.match(notificationIdPattern)?.[0] ?? null;
}

function notificationEntries(root: HTMLElement): HTMLElement[] {
    return [...root.querySelectorAll<HTMLElement>('.fi-no-notification-read-ctn, .fi-no-notification-unread-ctn')]
        .filter((entry) => entry.hasAttribute('wire:key'));
}

function refreshBaseDocumentTitle(): void {
    const title = document.title.replace(/^\(\d+\)\s+/, '').trim();
    if (title !== '') {
        baseDocumentTitle = title;
    }
}

function authoritativeUnreadCount(): number | null {
    const trigger = document.querySelector<HTMLElement>(
        '.fi-topbar-database-notifications-btn, .fi-sidebar-database-notifications-btn',
    );
    if (trigger === null) {
        return null;
    }

    const badge = trigger.querySelector<HTMLElement>(
        '.fi-icon-btn-badge-ctn .fi-badge, .fi-sidebar-database-notifications-btn-badge-ctn .fi-badge',
    );
    if (badge === null) {
        return 0;
    }

    const count = Number.parseInt((badge.textContent ?? '').replace(/[^\d]/g, ''), 10);
    return Number.isInteger(count) && count >= 0 ? count : null;
}

function updateDocumentTitle(): void {
    refreshBaseDocumentTitle();
    if (baseDocumentTitle === '') {
        return;
    }

    const unreadCount = authoritativeUnreadCount();
    if (unreadCount === null) {
        return;
    }

    document.title = unreadCount > 0 ? `(${unreadCount}) ${baseDocumentTitle}` : baseDocumentTitle;
}

function scanNotifications(): void {
    scanQueued = false;
    const databaseNotifications = document.getElementById('database-notifications');

    const notifications = databaseNotifications === null ? [] : notificationEntries(databaseNotifications);
    updateDocumentTitle();

    if (databaseNotifications === null) {
        return;
    }

    if (!notificationListInitialized) {
        notifications.forEach((notification) => {
            const id = notificationId(notification);
            if (id !== null) {
                seenNotificationIds.add(id);
            }
        });
        notificationListInitialized = true;
        return;
    }

    notifications.forEach((notification) => {
        const id = notificationId(notification);
        if (id === null || seenNotificationIds.has(id)) {
            return;
        }

        seenNotificationIds.add(id);
        const presentation = notification.querySelector<HTMLElement>('.fi-no-notification');
        if (presentation?.classList.contains('fi-status-warning') || presentation?.classList.contains('fi-status-danger')) {
            playNotificationSound();
        }
    });
}

function queueNotificationScan(): void {
    if (scanQueued) {
        return;
    }

    scanQueued = true;
    queueMicrotask(scanNotifications);
}

function resetNotificationBaseline(): void {
    notificationListInitialized = false;
    queueNotificationScan();
}

function registerLivewireHook(): void {
    const livewire = (window as Window & {
        Livewire?: {
            hook?: (name: string, callback: () => void) => void;
        };
    }).Livewire;

    livewire?.hook?.('morph.updated', queueNotificationScan);
}

function openDatabaseNotifications(event: MouseEvent): void {
    const target = event.target;

    if (!(target instanceof Element) || target.closest('.fi-topbar-database-notifications-btn, .fi-sidebar-database-notifications-btn') === null) {
        return;
    }

    window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: 'database-notifications' } }));
}

document.addEventListener('pointerdown', armAudio, { passive: true });
document.addEventListener('keydown', armAudio, { passive: true });
document.addEventListener('click', openDatabaseNotifications, true);
window.addEventListener('livewire:init', registerLivewireHook);
window.addEventListener('livewire:navigated', resetNotificationBaseline);

function initializeNotificationSound(): void {
    if (document.body === null) {
        return;
    }

    const observer = new MutationObserver(queueNotificationScan);
    observer.observe(document.body, {
        attributes: true,
        attributeFilter: ['class', 'wire:key'],
        childList: true,
        subtree: true,
    });
    queueNotificationScan();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeNotificationSound, { once: true });
} else {
    initializeNotificationSound();
}
