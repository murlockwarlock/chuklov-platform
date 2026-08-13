<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import { getTelegramInitData, resolveClientRuntime, type ClientRuntimeMode } from '../../runtime/clientRuntime';

type Service = {
    id: number;
    name: string;
    summary: string;
};

type Portal = {
    authenticated: boolean;
    clientName: string | null;
    telegramAuthUrl: string;
    telegramAuthError: string | null;
    telegramWebRequestUrl: string;
    telegramWebStatusUrl: string;
    telegramWebUrl: string | null;
    emailRequestUrl: string;
    emailVerifyUrl: string;
    emailCodeSent: boolean;
    telegramConnected: boolean;
    telegramLinkRequestUrl: string;
    telegramLinkUrl: string | null;
    telegramLinkError: boolean;
    onboardingUrl: string;
    bookingUrl: string | null;
    bookingsUrl: string | null;
};

const props = defineProps<{ services: Service[]; portal: Portal }>();
const runtimeMode: ClientRuntimeMode = resolveClientRuntime();
const authForm = useForm<{ initData: string }>({ initData: getTelegramInitData() ?? '' });
const telegramWebForm = useForm<Record<string, never>>({});
const emailRequestForm = useForm<{ email: string }>({ email: '' });
const emailVerifyForm = useForm<{ email: string; code: string }>({ email: '', code: '' });
const telegramLinkForm = useForm<Record<string, never>>({});
const authError = ref<string | null>(props.portal.telegramAuthError);
const telegramMiniAppAuthAttempted = ref(props.portal.telegramAuthError !== null);
const telegramMiniAppAuthFailed = ref(props.portal.telegramAuthError !== null);
let telegramStatusTimer: ReturnType<typeof setInterval> | null = null;

function stopTelegramStatusPolling(): void {
    if (telegramStatusTimer !== null) {
        clearInterval(telegramStatusTimer);
        telegramStatusTimer = null;
    }
}

async function checkTelegramWebAuthentication(): Promise<void> {
    try {
        const response = await fetch(props.portal.telegramWebStatusUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const result = await response.json() as { status: string; redirect?: string };

        if (result.status === 'authenticated' && result.redirect !== undefined) {
            stopTelegramStatusPolling();
            window.location.assign(result.redirect);
        } else if (result.status === 'expired') {
            stopTelegramStatusPolling();
            authError.value = 'Ссылка для входа истекла. Попробуйте ещё раз.';
        }
    } catch {
        authError.value = 'Не удалось проверить вход. Попробуйте ещё раз.';
    }
}

function startTelegramStatusPolling(): void {
    stopTelegramStatusPolling();
    void checkTelegramWebAuthentication();
    telegramStatusTimer = setInterval(() => void checkTelegramWebAuthentication(), 1500);
}

function requestTelegramWebAuthentication(): void {
    authError.value = null;
    const telegramWindow = window.open('about:blank', 'telegram-authentication');

    if (telegramWindow !== null) {
        telegramWindow.opener = null;
    }

    telegramWebForm.post(props.portal.telegramWebRequestUrl, {
        preserveScroll: true,
        onSuccess: () => {
            if (props.portal.telegramWebUrl === null) {
                authError.value = 'Сейчас вход через Telegram недоступен.';

                return;
            }

            startTelegramStatusPolling();
            if (telegramWindow !== null) {
                telegramWindow.location.assign(props.portal.telegramWebUrl);
            }
        },
        onError: () => {
            telegramWindow?.close();
            authError.value = 'Сейчас вход через Telegram недоступен.';
        },
    });
}

onMounted(() => {
    if (!props.portal.authenticated && runtimeMode === 'telegram-mini-app') {
        authenticateWithTelegram(true);
    }

    if (props.portal.telegramWebUrl !== null) {
        startTelegramStatusPolling();
    }
});

onUnmounted(stopTelegramStatusPolling);

function authenticateWithTelegram(automatic = false): void {
    if (automatic && telegramMiniAppAuthAttempted.value) {
        return;
    }

    authError.value = null;
    telegramMiniAppAuthFailed.value = false;

    if (runtimeMode === 'telegram-mini-app') {
        telegramMiniAppAuthAttempted.value = true;
    }

    if (authForm.initData === '') {
        authError.value = 'Не удалось получить данные для входа. Откройте приложение заново.';
        telegramMiniAppAuthFailed.value = true;

        return;
    }

    authForm.post(props.portal.telegramAuthUrl, {
        preserveScroll: true,
        onError: () => {
            authError.value = 'Не удалось войти через Telegram. Закройте приложение и откройте его снова.';
            telegramMiniAppAuthFailed.value = true;
        },
    });
}

function retryTelegramMiniAppAuthentication(): void {
    window.location.reload();
}

function requestEmailCode(): void {
    authError.value = null;
    emailRequestForm.post(props.portal.emailRequestUrl, {
        preserveScroll: true,
        onSuccess: () => {
            emailVerifyForm.email = emailRequestForm.email;
        },
        onError: () => {
            authError.value = 'Не удалось отправить код. Попробуйте ещё раз.';
        },
    });
}

function verifyEmailCode(): void {
    authError.value = null;
    emailVerifyForm.post(props.portal.emailVerifyUrl, {
        preserveScroll: true,
        onError: () => {
            authError.value = 'Код неверный или уже истёк.';
        },
    });
}

function requestTelegramLink(): void {
    authError.value = null;
    telegramLinkForm.post(props.portal.telegramLinkRequestUrl, {
        preserveScroll: true,
        onError: () => {
            authError.value = 'Сейчас не удалось подключить Telegram. Попробуйте ещё раз.';
        },
    });
}
</script>

<template>
  <Head title="Личный кабинет" />
  <main class="portal-page">
    <section class="portal-container portal-container--wide portal-stack portal-stack--loose">
      <header class="portal-masthead">
        <h1 class="portal-heading portal-heading--page">
          Личный кабинет
        </h1>
      </header>

      <section
        class="portal-grid"
        aria-labelledby="client-access-heading"
      >
        <div class="portal-panel portal-panel--accent portal-stack portal-stack--tight">
          <h2
            id="client-access-heading"
            class="portal-heading portal-heading--section"
          >
            Вход
          </h2>
          <p
            v-if="props.portal.authenticated"
            class="portal-copy"
          >
            Вы вошли как {{ props.portal.clientName }}.
          </p>

          <Link
            v-if="props.portal.authenticated"
            :href="props.portal.onboardingUrl"
            class="portal-button portal-button--primary self-start"
          >
            Продолжить
          </Link>
          <Link
            v-if="props.portal.bookingUrl"
            :href="props.portal.bookingUrl"
            class="portal-button portal-button--secondary self-start"
          >
            Записаться
          </Link>
          <Link
            v-if="props.portal.bookingsUrl"
            :href="props.portal.bookingsUrl"
            class="portal-button portal-button--secondary self-start"
          >
            Мои записи
          </Link>
          <div
            v-if="props.portal.authenticated"
            class="portal-stack portal-stack--tight"
          >
            <p class="portal-copy portal-copy--small">
              Telegram
            </p>
            <p
              v-if="props.portal.telegramConnected"
              class="portal-notice"
              role="status"
            >
              Telegram подключён.
            </p>
            <button
              v-else
              type="button"
              :disabled="telegramLinkForm.processing"
              class="portal-button portal-button--secondary self-start"
              @click="requestTelegramLink"
            >
              {{ telegramLinkForm.processing ? 'Подготовка…' : 'Подключить Telegram' }}
            </button>
            <a
              v-if="props.portal.telegramLinkUrl"
              :href="props.portal.telegramLinkUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="portal-link"
            >
              Открыть Telegram
            </a>
            <p
              v-if="props.portal.telegramLinkError"
              class="portal-notice portal-notice--error"
              role="alert"
            >
              Сейчас подключение Telegram недоступно.
            </p>
          </div>
          <div
            v-else-if="runtimeMode === 'telegram-mini-app'"
            class="portal-stack portal-stack--tight"
          >
            <p
              v-if="!telegramMiniAppAuthFailed"
              class="portal-notice"
              role="status"
            >
              Выполняем вход…
            </p>
            <template v-else>
              <p
                class="portal-notice portal-notice--error"
                role="alert"
              >
                {{ authError ?? 'Не удалось войти через Telegram. Откройте приложение заново.' }}
              </p>
              <button
                type="button"
                class="portal-button portal-button--secondary self-start"
                @click="retryTelegramMiniAppAuthentication"
              >
                Открыть приложение снова
              </button>
            </template>
          </div>
          <div
            v-else
            class="portal-stack portal-stack--tight"
          >
            <button
              type="button"
              :disabled="telegramWebForm.processing"
              class="portal-button portal-button--primary self-start"
              @click="requestTelegramWebAuthentication"
            >
              {{ telegramWebForm.processing ? 'Подготовка…' : 'Войти через тг' }}
            </button>
            <a
              v-if="props.portal.telegramWebUrl"
              :href="props.portal.telegramWebUrl"
              class="portal-link self-start"
            >
              Открыть Telegram
            </a>
            <p class="portal-separator">
              или
            </p>
            <form
              class="portal-stack portal-stack--tight"
              @submit.prevent="props.portal.emailCodeSent ? verifyEmailCode() : requestEmailCode()"
            >
              <div
                v-if="!props.portal.emailCodeSent"
                class="portal-field"
              >
                <label
                  for="portal-email"
                  class="portal-label"
                >Email</label>
                <input
                  id="portal-email"
                  v-model="emailRequestForm.email"
                  type="email"
                  required
                  autocomplete="email"
                  class="portal-input"
                >
              </div>
              <template v-else>
                <div class="portal-field">
                  <label
                    for="portal-verify-email"
                    class="portal-label"
                  >Email</label>
                  <input
                    id="portal-verify-email"
                    v-model="emailVerifyForm.email"
                    type="email"
                    required
                    autocomplete="email"
                    class="portal-input"
                  >
                </div>
                <div class="portal-field">
                  <label
                    for="portal-email-code"
                    class="portal-label"
                  >Код</label>
                  <input
                    id="portal-email-code"
                    v-model="emailVerifyForm.code"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    minlength="6"
                    maxlength="6"
                    required
                    autocomplete="one-time-code"
                    class="portal-input"
                  >
                </div>
                <p
                  class="portal-notice"
                  role="status"
                >
                  Код отправлен на указанную почту.
                </p>
              </template>
              <button
                type="submit"
                :disabled="emailRequestForm.processing || emailVerifyForm.processing"
                class="portal-button portal-button--primary self-start"
              >
                {{ (emailRequestForm.processing || emailVerifyForm.processing) ? 'Подождите…' : props.portal.emailCodeSent ? 'Войти' : 'Получить код' }}
              </button>
            </form>
          </div>
          <p
            v-if="authError"
            class="portal-notice portal-notice--error"
            role="alert"
          >
            {{ authError }}
          </p>
        </div>
      </section>

      <div
        v-if="services.length"
        class="portal-grid portal-grid--cards"
      >
        <article
          v-for="service in services"
          :key="service.id"
          class="portal-card"
        >
          <h2 class="portal-heading portal-heading--card">
            {{ service.name }}
          </h2>
          <p class="portal-card__summary">
            {{ service.summary }}
          </p>
        </article>
      </div>
      <p
        v-else
        class="portal-empty"
      >
        Услуги пока не опубликованы.
      </p>
    </section>
  </main>
</template>
