<script setup lang="ts">
import { computed, ref } from 'vue';
import SafeRichText from './SafeRichText.vue';
import PortalIcon from './PortalIcon.vue';
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
const marketingDocument = computed(() => props.documents.find((item) => item.documentType === 'marketing') ?? null);
const openDocument = computed(() => props.documents.find((item) => item.id === openDocumentId.value) ?? null);

function toggleDocument(id: number): void {
    openDocumentId.value = openDocumentId.value === id ? null : id;
}

function openDocumentLink(document: LegalDocument): void {
    if (props.groupRequiredAcceptance) {
        openDocumentId.value = document.id;

        return;
    }

    toggleDocument(document.id);
}

function closeDocument(): void {
    openDocumentId.value = null;
}
</script>

<template>
  <div
    class="portal-stack"
    :class="{ 'portal-legal-consent-group': props.groupRequiredAcceptance }"
  >
    <div
      class="portal-legal-documents"
      role="list"
      :aria-label="t('profile.legal')"
    >
      <article
        v-for="document in requiredDocuments"
        :key="document.id"
        class="portal-legal-document-row"
        role="listitem"
      >
        <label class="portal-confirm">
          <input
            type="checkbox"
            class="portal-checkbox"
            :checked="props.values[document.id] === true"
            :aria-invalid="props.requiredAcceptanceError ? 'true' : undefined"
            @change="emit('change', document.id, ($event.target as HTMLInputElement).checked)"
          >
          <span>
            <template v-if="document.documentType === 'privacy'">
              {{ t('legal.acceptPrivacyPrefix') }}
              <button
                type="button"
                class="portal-link portal-link--button"
                @click.prevent.stop="openDocumentLink(document)"
              >
                {{ t('legal.acceptPrivacyLink') }}
              </button>
            </template>
            <template v-else>
              {{ t('legal.acceptDocumentPrefix') }}
              <button
                type="button"
                class="portal-link portal-link--button"
                @click.prevent.stop="openDocumentLink(document)"
              >
                {{ document.title }}
              </button>
            </template>
          </span>
        </label>
        <div
          v-if="!props.groupRequiredAcceptance && openDocumentId === document.id"
          class="portal-legal-content portal-legal-document-row__content"
          role="region"
          :aria-label="document.title"
        >
          <SafeRichText
            :content="document.content"
            :content-html="document.contentHtml"
          />
        </div>
      </article>
    </div>

    <p
      v-if="props.requiredAcceptanceError"
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
            {{ t('legal.marketing') }}
          </h3>
          <p class="portal-copy portal-copy--small">
            {{ t('legal.marketingDescription') }}
          </p>
        </div>
        <span class="portal-copy portal-copy--small">{{ t('legal.optional') }}</span>
      </div>
      <label class="portal-confirm">
        <input
          type="checkbox"
          class="portal-checkbox"
          :checked="props.marketingValue"
          @change="emit('update:marketingValue', ($event.target as HTMLInputElement).checked)"
        >
        <span>
          {{ t('legal.acceptMarketing') }}
          <button
            v-if="marketingDocument"
            type="button"
            class="portal-link portal-link--button"
            @click.prevent.stop="openDocumentLink(marketingDocument)"
          >
            {{ marketingDocument.title }}
          </button>
        </span>
      </label>
      <div
        v-if="!props.groupRequiredAcceptance && marketingDocument && openDocumentId === marketingDocument.id"
        class="portal-legal-content"
        role="region"
        :aria-label="marketingDocument.title"
      >
        <SafeRichText
          :content="marketingDocument.content"
          :content-html="marketingDocument.contentHtml"
        />
      </div>
    </article>

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
              <PortalIcon name="close" />
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
