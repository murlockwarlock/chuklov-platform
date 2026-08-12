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
  <main
    class="portal-page"
    :data-locale="props.locale"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <article
        v-for="(item, index) in props.content"
        :key="`${item.locale}-${item.sortOrder}-${index}`"
        class="portal-panel portal-stack portal-stack--tight"
        :data-locale="item.locale"
        :data-sort-order="item.sortOrder"
      >
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            {{ props.locale }}
          </p>
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
        Back to portal
      </Link>
    </section>
  </main>
</template>
