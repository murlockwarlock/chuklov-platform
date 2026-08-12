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
    onboardingUrl: string;
};

const props = defineProps<{ services: Service[]; portal: Portal }>();
const runtimeMode: ClientRuntimeMode = resolveClientRuntime();
const authForm = useForm<{ initData: string }>({ initData: getTelegramInitData() ?? '' });
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
            Ordinary web authentication is still awaiting the approved product decision (OQ-001).
          </p>

          <Link
            v-if="props.portal.authenticated"
            :href="props.portal.onboardingUrl"
            class="portal-button portal-button--primary self-start"
          >
            Continue onboarding
          </Link>
          <button
            v-else-if="runtimeMode === 'telegram-mini-app'"
            type="button"
            :disabled="authForm.processing"
            class="portal-button portal-button--primary self-start"
            @click="authenticateWithTelegram"
          >
            {{ authForm.processing ? 'Verifying…' : 'Continue with Telegram' }}
          </button>
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
