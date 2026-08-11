<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { resolveClientRuntime } from '../../runtime/clientRuntime';

type Service = {
    id: number;
    name: string;
    summary: string;
};

defineProps<{ services: Service[] }>();

const runtimeLabel = computed(() =>
    resolveClientRuntime() === 'telegram-mini-app' ? 'Telegram Mini App' : 'Responsive web',
);
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
