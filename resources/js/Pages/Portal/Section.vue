<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    section: string;
    title: { en: string; ru: string };
}>();
</script>

<template>
  <Head :title="title.en" />
  <main class="min-h-screen bg-stone-950 text-stone-100">
    <section class="mx-auto flex max-w-3xl flex-col gap-6 px-5 py-12 sm:px-8 sm:py-16">
      <p class="text-xs font-semibold tracking-[0.2em] text-amber-300 uppercase">
        Telegram menu
      </p>
      <h1 class="text-4xl font-semibold tracking-tight">
        {{ title.en }}
      </h1>
      <p class="rounded-2xl border border-dashed border-stone-700 bg-stone-900 p-6 leading-7 text-stone-300">
        This localized entry point is configured for the Client Portal foundation. CRM-managed content is deferred to its accepted milestone.
      </p>
      <Link
        href="/"
        class="self-start rounded-xl border border-stone-700 px-4 py-3 text-sm font-medium text-stone-200 hover:border-stone-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-200"
      >
        Back to portal
      </Link>
    </section>
  </main>
</template>
