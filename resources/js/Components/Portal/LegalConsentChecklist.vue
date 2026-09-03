<script setup lang="ts">
import { ref } from 'vue';
import SafeRichText from './SafeRichText.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';

type LegalDocument = {
    id: number;
    documentType: string;
    title: string;
    content: string;
    contentHtml: string;
    version: string;
    isRequired: boolean;
};

const props = defineProps<{
    documents: LegalDocument[];
    values: Record<number, boolean>;
    marketingValue: boolean;
    showMarketing: boolean;
}>();

const emit = defineEmits<{
    change: [id: number, granted: boolean];
    'update:marketingValue': [granted: boolean];
}>();

const { t } = usePortalLocale();
const openDocumentId = ref<number | null>(null);

function toggleDocument(id: number): void {
    openDocumentId.value = openDocumentId.value === id ? null : id;
}
</script>

<template>
  <div class="portal-stack">
    <article
      v-for="document in props.documents.filter((item) => item.isRequired)"
      :key="document.id"
      class="portal-legal-card"
    >
      <div class="portal-section-heading">
        <h3 class="portal-heading portal-heading--card">
          {{ document.title }}
        </h3>
        <span class="portal-copy portal-copy--small">
          {{ t('profile.required') }}
        </span>
      </div>
      <button
        type="button"
        class="portal-link portal-link--button self-start"
        :aria-expanded="openDocumentId === document.id"
        @click="toggleDocument(document.id)"
      >
        {{ t('legal.read') }}
      </button>
      <div
        v-if="openDocumentId === document.id"
        class="portal-legal-content"
        role="region"
        :aria-label="document.title"
      >
        <SafeRichText
          :content="document.content"
          :content-html="document.contentHtml"
        />
      </div>
      <label class="portal-confirm">
        <input
          type="checkbox"
          class="portal-checkbox"
          :checked="props.values[document.id] === true"
          @change="emit('change', document.id, ($event.target as HTMLInputElement).checked)"
        >
        <span>{{ t('legal.acceptRequired') }}</span>
      </label>
    </article>

    <article
      v-if="props.showMarketing"
      class="portal-legal-card"
    >
      <div class="portal-section-heading">
        <h3 class="portal-heading portal-heading--card">
          {{ props.documents.find((item) => item.documentType === 'marketing')?.title ?? t('legal.marketing') }}
        </h3>
        <span class="portal-copy portal-copy--small">
          {{ t('legal.optional') }}
        </span>
      </div>
      <p class="portal-copy portal-copy--small">
        {{ t('legal.marketingDescription') }}
      </p>
      <label class="portal-confirm">
        <input
          type="checkbox"
          class="portal-checkbox"
          :checked="props.marketingValue"
          @change="emit('update:marketingValue', ($event.target as HTMLInputElement).checked)"
        >
        <span>{{ t('legal.acceptMarketing') }}</span>
      </label>
    </article>
  </div>
</template>
