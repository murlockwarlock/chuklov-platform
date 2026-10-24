export type ClientRuntimeMode = 'web' | 'telegram-mini-app';

declare global {
    interface Window {
        Telegram?: {
            WebApp?: {
                initData?: string;
                ready(): void;
                openTelegramLink?(url: string): void;
            };
        };
    }
}

export function shareTelegramLink(url: string): void {
    const shareUrl = 'https://t.me/share/url?' + new URLSearchParams({ url }).toString();
    if (window.Telegram?.WebApp?.openTelegramLink) {
        window.Telegram.WebApp.openTelegramLink(shareUrl);
    } else {
        window.open(shareUrl, '_blank', 'noopener,noreferrer');
    }
}

export function resolveClientRuntime(): ClientRuntimeMode {
    if (getTelegramInitData() !== null && window.Telegram?.WebApp) {
        window.Telegram.WebApp.ready();

        return 'telegram-mini-app';
    }

    return 'web';
}

export function getTelegramInitData(): string | null {
    const initData = window.Telegram?.WebApp?.initData?.trim();

    return initData === '' || initData === undefined ? null : initData;
}
