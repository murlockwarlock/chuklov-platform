<script setup lang="ts">
import { computed, ref } from 'vue';
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
    groupRequiredAcceptance?: boolean;
    requiredAcceptanceError?: string;
}>();

const emit = defineEmits<{
    change: [id: number, granted: boolean];
    'update:marketingValue': [granted: boolean];
}>();

const { t } = usePortalLocale();
const openDocumentId = ref<number | null>(null);
const requiredDocuments = computed(() => props.documents.filter((item) => item.isRequired));
const openDocument = computed(() => props.documents.find((item) => item.id === openDocumentId.value) ?? null);
const requiredAccepted = computed(() => requiredDocuments.value.length > 0
    && requiredDocuments.value.every((document) => props.values[document.id] === true));

function toggleDocument(id: number): void {
    openDocumentId.value = openDocumentId.value === id ? null : id;
}

function openDocumentModal(id: number): void {
    openDocumentId.value = id;
}

function closeDocument(): void {
    openDocumentId.value = null;
}

function setRequiredAcceptance(granted: boolean): void {
    requiredDocuments.value.forEach((document) => emit('change', document.id, granted));
}
</script>

<template>
  <div class="portal-legal-consent-root">
    <div
      v-if="props.groupRequiredAcceptance"
      class="portal-legal-consent-group portal-stack"
    >
      <div
        class="portal-legal-documents"
        role="list"
        :aria-label="t('profile.legal')"
      >
        <div
          v-for="document in requiredDocuments"
          :key="document.id"
          class="portal-legal-document-row"
          role="listitem"
        >
          <span class="portal-legal-document-row__title">{{ document.title }}</span>
          <button
            type="button"
            class="portal-link portal-link--button portal-legal-document-row__action"
            :aria-label="`${t('legal.open')}: ${document.title}`"
            @click="openDocumentModal(document.id)"
          >
            {{ t('legal.open') }}
          </button>
        </div>
      </div>

      <label
        class="portal-confirm portal-legal-required-confirm"
        :class="{ 'portal-legal-required-confirm--invalid': props.requiredAcceptanceError }"
        for="booking-required-consents"
      >
        <input
          id="booking-required-consents"
          type="checkbox"
          class="portal-checkbox"
          :checked="requiredAccepted"
          :aria-invalid="props.requiredAcceptanceError ? 'true' : undefined"
          :aria-describedby="props.requiredAcceptanceError ? 'booking-required-consents-error' : undefined"
          @change="setRequiredAcceptance(($event.target as HTMLInputElement).checked)"
        >
        <span>{{ t('legal.acceptRequiredSet') }}</span>
      </label>
      <p
        v-if="props.requiredAcceptanceError"
        id="booking-required-consents-error"
        class="portal-error"
        role="alert"
      >
        {{ props.requiredAcceptanceError }}
      </p>

      <article
        v-if="props.showMarketing"
        class="portal-legal-marketing"
      >
        <div class="portal-section-heading">
          <div class="portal-stack portal-stack--tight">
            <h3 class="portal-heading portal-heading--card">
              {{ props.documents.find((item) => item.documentType === 'marketing')?.title ?? t('legal.marketing') }}
            </h3>
            <p class="portal-copy portal-copy--small">
              {{ t('legal.marketingDescription') }}
            </p>
          </div>
          <span class="portal-copy portal-copy--small">
            {{ t('legal.optional') }}
          </span>
        </div>
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

    <div
      v-else
      class="portal-stack"
    >
      <article
        v-for="document in requiredDocuments"
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

    <Teleport to="body">
      <div
        v-if="props.groupRequiredAcceptance && openDocument !== null"
        class="portal-legal-modal"
        role="presentation"
        @click.self="closeDocument"
      >
        <section
          class="portal-legal-modal__dialog"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="`legal-document-modal-title-${openDocument.id}`"
        >
          <header class="portal-legal-modal__header">
            <h2
              :id="`legal-document-modal-title-${openDocument.id}`"
              class="portal-heading portal-heading--card"
            >
              {{ openDocument.title }}
            </h2>
            <button
              type="button"
              class="portal-legal-modal__close"
              :aria-label="t('common.close')"
              @click="closeDocument"
            >
              ×
            </button>
          </header>
          <div class="portal-legal-modal__content">
            <SafeRichText
              :content="openDocument.content"
              :content-html="openDocument.contentHtml"
            />
          </div>
          <footer class="portal-legal-modal__footer">
            <button
              type="button"
              class="portal-button portal-button--secondary"
              @click="closeDocument"
            >
              {{ t('common.close') }}
            </button>
          </footer>
        </section>
      </div>
    </Teleport>
  </div>
</template>
