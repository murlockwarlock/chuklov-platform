<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
    emailRequestUrl: string;
    emailVerifyUrl: string;
    emailCodeSent: boolean;
    telegramConnected: boolean;
    telegramLinkRequestUrl: string;
    telegramLinkUrl: string | null;
    telegramLinkError: boolean;
    onboardingUrl: string;
};

const props = defineProps<{ services: Service[]; portal: Portal }>();
const runtimeMode: ClientRuntimeMode = resolveClientRuntime();
const authForm = useForm<{ initData: string }>({ initData: getTelegramInitData() ?? '' });
const emailRequestForm = useForm<{ email: string }>({ email: '' });
const emailVerifyForm = useForm<{ email: string; code: string }>({ email: '', code: '' });
const telegramLinkForm = useForm<Record<string, never>>({});
const authError = ref<string | null>(null);

const runtimeLabel = computed(() =>
    runtimeMode === 'telegram-mini-app' ? 'Telegram Mini App' : 'Responsive web',
);

function authenticateWithTelegram(): void {
    authError.value = null;

    if (authForm.initData === '') {
        authError.value = 'Telegram could not provide a signed session payload.';

        return;
    }

    authForm.post(props.portal.telegramAuthUrl, {
        preserveScroll: true,
        onError: () => {
            authError.value = 'Telegram authentication was rejected. Please reopen the Mini App.';
        },
    });
}

function requestEmailCode(): void {
    authError.value = null;
    emailRequestForm.post(props.portal.emailRequestUrl, {
        preserveScroll: true,
        onSuccess: () => {
            emailVerifyForm.email = emailRequestForm.email;
        },
        onError: () => {
            authError.value = 'We could not send a verification code. Please try again shortly.';
        },
    });
}

function verifyEmailCode(): void {
    authError.value = null;
    emailVerifyForm.post(props.portal.emailVerifyUrl, {
        preserveScroll: true,
        onError: () => {
            authError.value = 'That verification code is invalid or expired.';
        },
    });
}

function requestTelegramLink(): void {
    authError.value = null;
    telegramLinkForm.post(props.portal.telegramLinkRequestUrl, {
        preserveScroll: true,
        onError: () => {
            authError.value = 'Telegram connection is not available right now.';
        },
    });
}
</script>

<template>
  <Head title="Services" />
  <main class="portal-page">
    <section class="portal-container portal-container--wide portal-stack portal-stack--loose">
      <header class="portal-masthead">
        <div class="portal-masthead__copy portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            {{ runtimeLabel }}
          </p>
          <h1 class="portal-heading portal-heading--page">
            Chuklov Client Portal
          </h1>
          <p class="portal-lede">
            A secure foundation for services, client care, and future channel experiences.
          </p>
        </div>
      </header>

      <section
        class="portal-grid portal-grid--access"
        aria-labelledby="client-access-heading"
      >
        <div class="portal-panel portal-panel--accent portal-stack portal-stack--tight">
          <h2
            id="client-access-heading"
            class="portal-heading portal-heading--section"
          >
            Client access
          </h2>
          <p
            v-if="props.portal.authenticated"
            class="portal-copy"
          >
            Signed in as {{ props.portal.clientName }}.
          </p>
          <p
            v-else-if="runtimeMode === 'telegram-mini-app'"
            class="portal-copy"
          >
            Continue with the signed Telegram session to open your client onboarding.
          </p>
          <p
            v-else
            class="portal-copy"
          >
            Use a short-lived code sent to your email. No reusable password is required.
          </p>

          <Link
            v-if="props.portal.authenticated"
            :href="props.portal.onboardingUrl"
            class="portal-button portal-button--primary self-start"
          >
            Continue onboarding
          </Link>
          <div
            v-if="props.portal.authenticated"
            class="portal-stack portal-stack--tight"
          >
            <p class="portal-copy portal-copy--small">
              Telegram connection
            </p>
            <p
              v-if="props.portal.telegramConnected"
              class="portal-notice"
              role="status"
            >
              Telegram is connected to this client portal account.
            </p>
            <button
              v-else
              type="button"
              :disabled="telegramLinkForm.processing"
              class="portal-button portal-button--secondary self-start"
              @click="requestTelegramLink"
            >
              {{ telegramLinkForm.processing ? 'Preparing link…' : 'Connect Telegram' }}
            </button>
            <a
              v-if="props.portal.telegramLinkUrl"
              :href="props.portal.telegramLinkUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="portal-link"
            >
              Open the Telegram connection link
            </a>
            <p
              v-if="props.portal.telegramLinkError"
              class="portal-notice portal-notice--error"
              role="alert"
            >
              Telegram connection is not configured for this organization.
            </p>
          </div>
          <button
            v-else-if="runtimeMode === 'telegram-mini-app'"
            type="button"
            :disabled="authForm.processing"
            class="portal-button portal-button--primary self-start"
            @click="authenticateWithTelegram"
          >
            {{ authForm.processing ? 'Verifying…' : 'Continue with Telegram' }}
          </button>
          <form
            v-else
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
              >Email address</label>
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
                >Email address</label>
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
                >Verification code</label>
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
                A one-time code has been sent. It expires shortly and can be used once.
              </p>
            </template>
            <button
              type="submit"
              :disabled="emailRequestForm.processing || emailVerifyForm.processing"
              class="portal-button portal-button--primary self-start"
            >
              {{ (emailRequestForm.processing || emailVerifyForm.processing) ? 'Working…' : props.portal.emailCodeSent ? 'Verify code' : 'Send code' }}
            </button>
          </form>
          <p
            v-if="authError"
            class="portal-notice portal-notice--error"
            role="alert"
          >
            {{ authError }}
          </p>
          <p
            v-if="authForm.errors.initData"
            class="portal-error"
            role="alert"
          >
            {{ authForm.errors.initData }}
          </p>
        </div>

        <div class="portal-panel portal-panel--quiet portal-panel--compact portal-stack portal-stack--tight">
          <p class="portal-kicker">
            Shared runtime
          </p>
          <p class="portal-copy portal-copy--small">
            Desktop web, mobile web, and Telegram Mini App use the same server-authorized client journey.
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
        Services will appear here after they are published in the CRM.
      </p>
    </section>
  </main>
</template>
