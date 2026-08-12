export type ClientRuntimeMode = 'web' | 'telegram-mini-app';

declare global {
    interface Window {
        Telegram?: {
            WebApp?: {
                initData?: string;
                ready(): void;
            };
        };
    }
}

export function resolveClientRuntime(): ClientRuntimeMode {
    if (window.Telegram?.WebApp) {
        window.Telegram.WebApp.ready();

        return 'telegram-mini-app';
    }

    return 'web';
}

export function getTelegramInitData(): string | null {
    const initData = window.Telegram?.WebApp?.initData?.trim();

    return initData === '' || initData === undefined ? null : initData;
}
