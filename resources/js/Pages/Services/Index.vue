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
  <main class="min-h-screen bg-stone-950 text-stone-100">
    <section class="mx-auto max-w-6xl px-5 py-14 sm:px-8 sm:py-20">
      <div class="mb-12 max-w-3xl">
        <p class="mb-4 text-xs font-semibold tracking-[0.24em] text-amber-300 uppercase">
          {{ runtimeLabel }}
        </p>
        <h1 class="text-4xl font-semibold tracking-tight sm:text-6xl">
          Chuklov Client Portal
        </h1>
        <p class="mt-5 max-w-2xl text-base leading-7 text-stone-300 sm:text-lg">
          A secure foundation for services, client care, and future channel experiences.
        </p>
      </div>

      <section
        class="mb-12 grid gap-5 md:grid-cols-[1.2fr_0.8fr]"
        aria-labelledby="client-access-heading"
      >
        <div class="rounded-2xl border border-amber-300/30 bg-amber-100/10 p-6">
          <h2
            id="client-access-heading"
            class="text-xl font-medium text-amber-100"
          >
            Client access
          </h2>
          <p
            v-if="props.portal.authenticated"
            class="mt-3 text-stone-300"
          >
            Signed in as {{ props.portal.clientName }}.
          </p>
          <p
            v-else-if="runtimeMode === 'telegram-mini-app'"
            class="mt-3 text-stone-300"
          >
            Continue with the signed Telegram session to open your client onboarding.
          </p>
          <p
            v-else
            class="mt-3 text-stone-300"
          >
            Ordinary web authentication is still awaiting the approved product decision (OQ-001).
          </p>

          <Link
            v-if="props.portal.authenticated"
            :href="props.portal.onboardingUrl"
            class="mt-5 inline-flex min-h-11 items-center rounded-xl bg-amber-300 px-5 py-3 font-semibold text-stone-950 transition hover:bg-amber-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-200"
          >
            Continue onboarding
          </Link>
          <button
            v-else-if="runtimeMode === 'telegram-mini-app'"
            type="button"
            :disabled="authForm.processing"
            class="mt-5 inline-flex min-h-11 items-center rounded-xl bg-amber-300 px-5 py-3 font-semibold text-stone-950 transition hover:bg-amber-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-200 disabled:cursor-not-allowed disabled:opacity-60"
            @click="authenticateWithTelegram"
          >
            {{ authForm.processing ? 'Verifying…' : 'Continue with Telegram' }}
          </button>
          <p
            v-if="authError"
            class="mt-3 text-sm text-red-300"
            role="alert"
          >
            {{ authError }}
          </p>
          <p
            v-if="authForm.errors.initData"
            class="mt-3 text-sm text-red-300"
            role="alert"
          >
            {{ authForm.errors.initData }}
          </p>
        </div>

        <div class="rounded-2xl border border-stone-800 bg-stone-900 p-6">
          <p class="text-xs font-semibold tracking-[0.2em] text-stone-500 uppercase">
            Shared runtime
          </p>
          <p class="mt-3 text-sm leading-6 text-stone-300">
            Desktop web, mobile web, and Telegram Mini App use the same server-authorized client journey.
          </p>
        </div>
      </section>

      <div
        v-if="services.length"
        class="grid gap-4 md:grid-cols-2"
      >
        <article
          v-for="service in services"
          :key="service.id"
          class="rounded-2xl border border-stone-800 bg-stone-900 p-6 shadow-xl shadow-black/10"
        >
          <h2 class="text-xl font-medium text-amber-100">
            {{ service.name }}
          </h2>
          <p class="mt-3 leading-7 text-stone-300">
            {{ service.summary }}
          </p>
        </article>
      </div>
      <p
        v-else
        class="rounded-2xl border border-dashed border-stone-700 p-8 text-stone-400"
      >
        Services will appear here after they are published in the CRM.
      </p>
    </section>
  </main>
</template>
