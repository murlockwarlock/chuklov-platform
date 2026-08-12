<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    section: string;
    title: { en: string; ru: string };
}>();
</script>

<template>
  <Head :title="title.en" />
  <main class="portal-page">
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <div class="portal-stack portal-stack--tight">
        <p class="portal-eyebrow">
          Telegram menu
        </p>
        <h1 class="portal-heading portal-heading--page">
          {{ title.en }}
        </h1>
      </div>
      <p class="portal-notice">
        This localized entry point is configured for the Client Portal foundation. CRM-managed content is deferred to its accepted milestone.
      </p>
      <Link
        href="/"
        class="portal-button portal-button--secondary self-start"
      >
        Back to portal
      </Link>
    </section>
  </main>
</template>
