<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import SafeRichText from '../../Components/Portal/SafeRichText.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Locale = 'en' | 'ru';

type ContentMedia = {
    image: string;
    alt?: string;
};

type ContentItem = {
    locale: Locale;
    title: string;
    body: string;
    bodyHtml: string;
    media: ContentMedia | null;
    sortOrder: number;
};

const props = defineProps<{
    portal: PortalShell;
    section: string;
    title: string;
    locale: Locale;
    content: ContentItem[];
}>();

const { t } = usePortalLocale();
const pageTitle = computed(() => props.title);
</script>

<template>
  <AppShell
    :title="pageTitle"
    :portal="props.portal"
    :bottom-navigation="false"
  >
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
          :alt="item.media.alt || item.title"
          class="max-w-full rounded-xl"
        >
        <SafeRichText
          :content="item.body"
          :content-html="item.bodyHtml"
        />
      </article>
      <EmptyState
        v-if="!props.content.length"
        :title="t('section.emptyTitle')"
        :description="t('section.emptyDescription')"
      />
      <Link
        :href="props.portal.urls.home"
        class="portal-button portal-button--secondary self-start"
      >
        {{ t('shell.home') }}
      </Link>
    </section>
  </AppShell>
</template>
