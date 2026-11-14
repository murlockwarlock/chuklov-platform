export {};

type RequestInterceptor = {
    onFailure: (callback: () => void) => void;
    onError: (callback: (payload: { response: Response; preventDefault: () => void }) => void) => void;
};

type LivewireBridge = {
    interceptRequest: (callback: (interceptor: RequestInterceptor) => void) => void;
};

declare global {
    interface Window {
        Livewire?: LivewireBridge;
    }
}

const bannerId = 'chuklov-livewire-network-error';
let installed = false;

function removeBanner(): void {
    document.getElementById(bannerId)?.remove();
}

function unlockLoadingControls(): void {
    document.querySelectorAll<HTMLElement>('[wire\\:loading\\.attr="disabled"]').forEach((element) => {
        element.removeAttribute('disabled');
    });
}

function showFailure(): void {
    unlockLoadingControls();

    if (document.getElementById(bannerId) !== null) {
        return;
    }

    const isEnglish = document.documentElement.lang === 'en';
    const banner = document.createElement('div');
    banner.id = bannerId;
    banner.setAttribute('role', 'alert');
    banner.setAttribute('aria-live', 'assertive');
    banner.style.cssText = [
        'position:fixed',
        'inset-inline-end:1rem',
        'bottom:1rem',
        'z-index:2147483647',
        'width:min(32rem, calc(100vw - 2rem))',
        'border:1px solid #fca5a5',
        'border-radius:0.75rem',
        'background:#fff7ed',
        'padding:1rem',
        'color:#7f1d1d',
        'box-shadow:0 10px 30px rgb(15 23 42 / 0.18)',
    ].join(';');

    const title = document.createElement('strong');
    title.textContent = isEnglish ? 'The action could not be confirmed' : 'Не удалось подтвердить действие';

    const message = document.createElement('p');
    message.style.cssText = 'margin:0.5rem 0 0;line-height:1.45';
    message.textContent = isEnglish
        ? 'Check the result after refreshing the page. If the action was not completed, try again. Values entered in the form will remain on the screen.'
        : 'Проверьте результат после обновления страницы. Если действие не выполнено, повторите его. Введённые значения формы останутся на экране.';

    const close = document.createElement('button');
    close.type = 'button';
    close.textContent = isEnglish ? 'Close' : 'Закрыть';
    close.style.cssText = 'margin-top:0.75rem;border:0;background:transparent;padding:0;color:inherit;font:inherit;font-weight:600;text-decoration:underline;cursor:pointer';
    close.addEventListener('click', removeBanner);

    banner.append(title, message, close);
    document.body.append(banner);
}

function install(): void {
    if (installed || window.Livewire === undefined) {
        return;
    }

    installed = true;
    window.Livewire.interceptRequest(({ onFailure, onError }) => {
        onFailure(() => showFailure());
        onError(({ response, preventDefault }) => {
            if (response.status >= 500) {
                preventDefault();
                showFailure();
            }
        });
    });
}

document.addEventListener('livewire:init', install, { once: true });
queueMicrotask(install);
