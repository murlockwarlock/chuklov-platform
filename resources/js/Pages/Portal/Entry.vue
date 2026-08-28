<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import { getTelegramInitData, resolveClientRuntime, type ClientRuntimeMode } from '../../runtime/clientRuntime';
import type { PortalShell } from '../../types/portal';

type AuthProps = {
    telegramAuthUrl: string;
    telegramAuthError: string | null;
    telegramLaunchEntry?: string | null;
    telegramWebRequestUrl: string;
    telegramWebStatusUrl: string;
    telegramWebUrl: string | null;
    emailRequestUrl: string;
    emailVerifyUrl: string;
    emailCodeSent: boolean;
};

const props = defineProps<{
    portal: PortalShell;
    auth: AuthProps;
}>();

const { t } = usePortalLocale();
const runtimeMode: ClientRuntimeMode = resolveClientRuntime();
const authForm = useForm<{ initData: string; launchEntry: string }>({
    initData: getTelegramInitData() ?? '',
    launchEntry: props.auth.telegramLaunchEntry ?? '',
});
const telegramWebForm = useForm<Record<string, never>>({});
const emailRequestForm = useForm<{ email: string }>({ email: '' });
const emailVerifyForm = useForm<{ email: string; code: string }>({ email: '', code: '' });
const authError = ref<string | null>(props.auth.telegramAuthError);
const telegramMiniAppAuthAttempted = ref(props.auth.telegramAuthError !== null);
const telegramMiniAppAuthFailed = ref(props.auth.telegramAuthError !== null);
let telegramStatusTimer: ReturnType<typeof setInterval> | null = null;

function stopTelegramStatusPolling(): void {
    if (telegramStatusTimer !== null) {
        clearInterval(telegramStatusTimer);
        telegramStatusTimer = null;
    }
}

async function checkTelegramWebAuthentication(): Promise<void> {
    try {
        const response = await fetch(props.auth.telegramWebStatusUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const result = await response.json() as { status: string; redirect?: string };

        if (result.status === 'authenticated' && result.redirect !== undefined) {
            stopTelegramStatusPolling();
            window.location.assign(result.redirect);
        } else if (result.status === 'expired') {
            stopTelegramStatusPolling();
            authError.value = t('entry.telegramExpired');
        }
    } catch {
        authError.value = t('entry.telegramError');
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

    telegramWebForm.post(props.auth.telegramWebRequestUrl, {
        preserveScroll: true,
        onSuccess: () => {
            if (props.auth.telegramWebUrl === null) {
                authError.value = t('entry.telegramUnavailable');

                return;
            }

            startTelegramStatusPolling();
            if (telegramWindow !== null) {
                telegramWindow.location.assign(props.auth.telegramWebUrl);
            }
        },
        onError: () => {
            telegramWindow?.close();
            authError.value = t('entry.telegramUnavailable');
        },
    });
}

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
        authError.value = t('entry.telegramAuthError');
        telegramMiniAppAuthFailed.value = true;

        return;
    }

    authForm.post(props.auth.telegramAuthUrl, {
        preserveScroll: true,
        onError: () => {
            authError.value = t('entry.telegramAuthError');
            telegramMiniAppAuthFailed.value = true;
        },
    });
}

function retryTelegramMiniAppAuthentication(): void {
    window.location.reload();
}

function requestEmailCode(): void {
    authError.value = null;
    emailRequestForm.post(props.auth.emailRequestUrl, {
        preserveScroll: true,
        onSuccess: () => {
            emailVerifyForm.email = emailRequestForm.email;
        },
        onError: () => {
            authError.value = t('entry.emailError');
        },
    });
}

function verifyEmailCode(): void {
    authError.value = null;
    emailVerifyForm.post(props.auth.emailVerifyUrl, {
        preserveScroll: true,
        onError: () => {
            authError.value = t('entry.emailVerifyError');
        },
    });
}

onMounted(() => {
    if (runtimeMode === 'telegram-mini-app') {
        authenticateWithTelegram(true);
    }

    if (props.auth.telegramWebUrl !== null) {
        startTelegramStatusPolling();
    }
});

onUnmounted(stopTelegramStatusPolling);
</script>

<template>
  <AppShell
    :title="t('entry.title')"
    :portal="props.portal"
    :bottom-navigation="false"
  >
    <section class="portal-entry">
      <div class="portal-entry__intro">
        <p class="portal-eyebrow">
          {{ t('entry.title') }}
        </p>
        <h1 class="portal-heading portal-heading--page">
          {{ t('entry.welcome') }}
        </h1>
        <p class="portal-lede">
          {{ t('entry.description') }}
        </p>
      </div>

      <section
        v-if="runtimeMode === 'telegram-mini-app'"
        class="portal-panel portal-entry__panel"
        aria-live="polite"
      >
        <p
          v-if="!telegramMiniAppAuthFailed"
          class="portal-copy"
        >
          {{ t('entry.telegramMiniApp') }}
        </p>
        <template v-else>
          <p class="portal-notice portal-notice--error">
            {{ authError }}
          </p>
          <button
            type="button"
            class="portal-button portal-button--secondary"
            @click="retryTelegramMiniAppAuthentication"
          >
            {{ t('entry.telegramRetry') }}
          </button>
        </template>
      </section>

      <section
        v-else
        class="portal-entry__access"
      >
        <div class="portal-panel portal-stack">
          <button
            type="button"
            class="portal-button portal-button--primary"
            :disabled="telegramWebForm.processing"
            @click="requestTelegramWebAuthentication"
          >
            {{ t('entry.telegram') }}
          </button>
          <p
            v-if="authError"
            class="portal-notice portal-notice--error"
            role="alert"
          >
            {{ authError }}
          </p>
        </div>

        <div class="portal-separator">
          <span>{{ t('entry.or') }}</span>
        </div>

        <form
          class="portal-panel portal-stack"
          @submit.prevent="emailRequestForm.processing ? undefined : requestEmailCode()"
        >
          <h2 class="portal-heading portal-heading--card">
            {{ t('entry.email') }}
          </h2>
          <div class="portal-field">
            <label
              for="portal-email"
              class="portal-label"
            >Email</label>
            <input
              id="portal-email"
              v-model="emailRequestForm.email"
              type="email"
              autocomplete="email"
              required
              class="portal-input"
              :placeholder="t('entry.emailPlaceholder')"
            >
          </div>
          <button
            type="submit"
            class="portal-button portal-button--secondary"
            :disabled="emailRequestForm.processing"
          >
            {{ t('entry.sendCode') }}
          </button>
          <p
            v-if="props.auth.emailCodeSent"
            class="portal-notice"
            role="status"
          >
            {{ t('entry.codeSent') }}
          </p>
        </form>

        <form
          v-if="props.auth.emailCodeSent"
          class="portal-panel portal-stack"
          @submit.prevent="verifyEmailCode"
        >
          <div class="portal-field">
            <label
              for="portal-email-code"
              class="portal-label"
            >{{ t('entry.code') }}</label>
            <input
              id="portal-email-code"
              v-model="emailVerifyForm.code"
              inputmode="numeric"
              autocomplete="one-time-code"
              required
              class="portal-input"
              :placeholder="t('entry.codePlaceholder')"
            >
          </div>
          <button
            type="submit"
            class="portal-button portal-button--primary"
            :disabled="emailVerifyForm.processing"
          >
            {{ t('entry.verifyCode') }}
          </button>
        </form>
      </section>

      <Link
        :href="props.portal.urls.services"
        class="portal-link"
      >
        {{ t('services.title') }}
      </Link>
    </section>
  </AppShell>
</template>
