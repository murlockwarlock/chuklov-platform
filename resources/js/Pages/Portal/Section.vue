<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

type Locale = 'en' | 'ru';

type ContentMedia = {
    image: string;
    alt?: string;
};

type ContentItem = {
    locale: Locale;
    title: string;
    body: string;
    media: ContentMedia | null;
    sortOrder: number;
};

const props = defineProps<{
    section: string;
    locale: Locale;
    content: ContentItem[];
}>();

const pageTitle = computed(() => props.content[0]?.title ?? props.section);
</script>

<template>
  <Head :title="pageTitle" />
  <main class="portal-page">
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <article
        v-for="(item, index) in props.content"
        :key="`${item.locale}-${item.sortOrder}-${index}`"
        class="portal-panel portal-stack portal-stack--tight"
      >
        <div class="portal-stack portal-stack--tight">
          <h1
            v-if="index === 0"
            class="portal-heading portal-heading--page"
          >
            {{ item.title }}
          </h1>
          <h2
            v-else
            class="portal-heading portal-heading--section"
          >
            {{ item.title }}
          </h2>
        </div>
        <img
          v-if="item.media"
          :src="item.media.image"
          :alt="item.media.alt ?? item.title"
          class="max-w-full rounded-xl"
        >
        <p class="portal-copy whitespace-pre-line">
          {{ item.body }}
        </p>
      </article>
      <Link
        href="/"
        class="portal-button portal-button--secondary self-start"
      >
        В личный кабинет
      </Link>
    </section>
  </main>
</template>
