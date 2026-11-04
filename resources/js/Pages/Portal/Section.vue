<script setup lang="ts">
import { computed } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import PortalPageHeader from '../../Components/Portal/PortalPageHeader.vue';
import SafeRichText from '../../Components/Portal/SafeRichText.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Locale = 'en' | 'ru';

type ContentMedia = {
    image?: string;
    alt?: string;
    communities?: Array<{ title: string; url: string; description: string | null }>;
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
    active="more"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <PortalPageHeader
        :title="pageTitle"
        :back-href="props.portal.urls.more"
        :back-label="t('common.back')"
      />
      <article
        v-for="(item, index) in props.content"
        :key="`${item.locale}-${item.sortOrder}-${index}`"
        class="portal-panel portal-stack portal-stack--tight"
      >
        <div class="portal-stack portal-stack--tight">
          <h2 class="portal-heading portal-heading--section">
            {{ item.title }}
          </h2>
        </div>
        <img
          v-if="item.media?.image"
          :src="item.media.image"
          :alt="item.media.alt || item.title"
          class="max-w-full rounded-xl"
        >
        <SafeRichText
          :content="item.body"
          :content-html="item.bodyHtml"
        />
        <ul
          v-if="item.media?.communities?.length"
          class="portal-list portal-stack portal-stack--tight"
        >
          <li
            v-for="community in item.media.communities"
            :key="community.url"
            class="portal-list__item"
          >
            <a
              :href="community.url"
              target="_blank"
              rel="noopener noreferrer"
              class="portal-link"
            >
              <strong>{{ community.title }}</strong>
            </a>
            <p v-if="community.description">
              {{ community.description }}
            </p>
          </li>
        </ul>
      </article>
      <EmptyState
        v-if="!props.content.length"
        :title="t('section.emptyTitle')"
        :description="t('section.emptyDescription')"
      />
    </section>
  </AppShell>
</template>
