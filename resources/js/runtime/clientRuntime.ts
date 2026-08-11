export type ClientRuntimeMode = 'web' | 'telegram-mini-app';

declare global {
    interface Window {
        Telegram?: { WebApp?: { ready(): void } };
    }
}

export function resolveClientRuntime(): ClientRuntimeMode {
    if (window.Telegram?.WebApp) {
        window.Telegram.WebApp.ready();

        return 'telegram-mini-app';
    }

    return 'web';
}
