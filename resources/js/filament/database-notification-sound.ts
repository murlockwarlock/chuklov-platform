const seenNotificationIds = new Set<string>();
let notificationListInitialized = false;
let scanQueued = false;
let audioContext: AudioContext | null = null;

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
        gain.gain.setValueAtTime(0.035, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.12);
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

function scanNotifications(): void {
    scanQueued = false;
    const databaseNotifications = document.querySelector<HTMLElement>('.fi-no-database');

    if (databaseNotifications === null || databaseNotifications.querySelector('#database-notifications') === null) {
        return;
    }

    const notifications = [...databaseNotifications.querySelectorAll<HTMLElement>('.fi-no-notification')];

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
        if (notification.classList.contains('fi-status-warning') || notification.classList.contains('fi-status-danger')) {
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

document.addEventListener('pointerdown', armAudio, { passive: true });
document.addEventListener('keydown', armAudio, { passive: true });
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
