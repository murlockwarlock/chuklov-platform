<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps<{
    section: string;
    content: Record<string, { title: string; body: string; media: Record<string, string> | null }>;
}>();
</script>

<template>
  <Head :title="props.content.en?.title ?? props.content.ru?.title ?? props.section" />
  <main class="portal-page">
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <div class="portal-stack portal-stack--tight">
        <p class="portal-eyebrow">
          Telegram menu
        </p>
        <h1 class="portal-heading portal-heading--page">
          {{ props.content.en?.title ?? props.content.ru?.title ?? props.section }}
        </h1>
      </div>
      <p class="portal-copy whitespace-pre-line">
        {{ props.content.en?.body ?? props.content.ru?.body }}
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
