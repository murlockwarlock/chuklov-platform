<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type LegalDocument = {
    id: number;
    purpose: string;
    content: string;
    isRequired: boolean;
    accepted: boolean;
};

type Profile = {
    fullName: string | null;
    email: string | null;
    phone: string | null;
    locale: 'ru' | 'en';
    emailEditable: boolean;
};

type Telegram = {
    connected: boolean;
    linkUrl?: string | null;
    linkError?: boolean;
};

const props = defineProps<{
    portal: PortalShell;
    profile: Profile;
    telegram: Telegram;
    legalDocuments: LegalDocument[];
    urls: {
        update: string;
        consents: string;
        telegramLink: string;
    };
    saved: boolean;
    consentsSaved: boolean;
}>();

const { locale, t } = usePortalLocale();
const profileForm = useForm<{
    full_name: string;
    email: string;
    phone: string;
}>({
    full_name: props.profile.fullName ?? '',
    email: props.profile.email ?? '',
    phone: props.profile.phone ?? '',
});
const consentForm = useForm<{
    consents: Array<{ legal_document_id: number; granted: boolean }>;
}>({
    consents: props.legalDocuments.map((document) => ({
        legal_document_id: document.id,
        granted: document.accepted,
    })),
});
const telegramLinkForm = useForm<Record<string, never>>({});
const hasRequiredConsentError = computed(() =>
    Object.keys(consentForm.errors).some((key) => key.startsWith('consents')),
);

function saveProfile(): void {
    profileForm.post(props.urls.update, {
        preserveScroll: true,
    });
}

function saveConsents(): void {
    consentForm.post(props.urls.consents, {
        preserveScroll: true,
    });
}

function requestTelegramLink(): void {
    telegramLinkForm.post(props.urls.telegramLink, {
        preserveScroll: true,
    });
}
</script>

<template>
  <AppShell
    :title="t('profile.title')"
    :portal="props.portal"
    active="profile"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            CHUKLOV
          </p>
          <h1 class="portal-heading portal-heading--section">
            {{ t('profile.title') }}
          </h1>
        </div>
      </header>

      <section class="portal-panel portal-stack">
        <div class="portal-section-heading">
          <span class="portal-label">{{ t('profile.language') }}</span>
          <span class="portal-copy portal-copy--small">
            {{ locale === 'ru' ? t('profile.russian') : t('profile.english') }}
          </span>
        </div>
        <form
          class="portal-stack"
          @submit.prevent="saveProfile"
        >
          <div class="portal-grid portal-grid--two">
            <div class="portal-field">
              <label
                for="profile-full-name"
                class="portal-label"
              >{{ t('profile.name') }}</label>
              <input
                id="profile-full-name"
                v-model="profileForm.full_name"
                type="text"
                autocomplete="name"
                class="portal-input"
              >
              <p
                v-if="profileForm.errors.full_name"
                class="portal-error"
              >
                {{ profileForm.errors.full_name }}
              </p>
            </div>

            <div class="portal-field">
              <label
                for="profile-phone"
                class="portal-label"
              >{{ t('profile.phone') }}</label>
              <input
                id="profile-phone"
                v-model="profileForm.phone"
                type="tel"
                autocomplete="tel"
                class="portal-input"
                :placeholder="t('profile.notSpecified')"
              >
              <p
                v-if="profileForm.errors.phone"
                class="portal-error"
              >
                {{ profileForm.errors.phone }}
              </p>
            </div>

            <div class="portal-field portal-field--wide">
              <label
                for="profile-email"
                class="portal-label"
              >{{ t('profile.email') }}</label>
              <input
                id="profile-email"
                v-model="profileForm.email"
                type="email"
                autocomplete="email"
                class="portal-input"
                :disabled="!props.profile.emailEditable"
                :placeholder="t('profile.notSpecified')"
              >
              <p
                v-if="!props.profile.emailEditable"
                class="portal-copy portal-copy--small"
              >
                {{ t('profile.emailManaged') }}
              </p>
              <p
                v-if="profileForm.errors.email"
                class="portal-error"
              >
                {{ profileForm.errors.email }}
              </p>
            </div>
          </div>
          <div class="portal-form-actions">
            <button
              type="submit"
              class="portal-button portal-button--primary"
              :disabled="profileForm.processing"
            >
              {{ profileForm.processing ? t('profile.saving') : t('profile.save') }}
            </button>
            <p
              v-if="props.saved"
              class="portal-notice"
              role="status"
            >
              {{ t('profile.saved') }}
            </p>
          </div>
        </form>
      </section>

      <section class="portal-panel portal-stack">
        <div class="portal-section-heading">
          <div class="portal-stack portal-stack--tight">
            <h2 class="portal-heading portal-heading--card">
              {{ t('profile.telegram') }}
            </h2>
            <p class="portal-copy portal-copy--small">
              {{ props.telegram.connected
                ? t('profile.telegramConnected')
                : t('profile.notSpecified') }}
            </p>
          </div>
          <span
            v-if="props.telegram.connected"
            class="portal-status-dot"
            aria-hidden="true"
          />
        </div>
        <button
          v-if="!props.telegram.connected"
          type="button"
          class="portal-button portal-button--secondary self-start"
          :disabled="telegramLinkForm.processing"
          @click="requestTelegramLink"
        >
          {{ t('profile.telegramConnect') }}
        </button>
        <a
          v-if="props.telegram.linkUrl"
          :href="props.telegram.linkUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="portal-link"
        >
          {{ t('profile.openTelegram') }}
        </a>
        <p
          v-if="props.telegram.linkError"
          class="portal-notice portal-notice--error"
          role="alert"
        >
          {{ t('profile.telegramUnavailable') }}
        </p>
      </section>

      <section class="portal-panel portal-stack">
        <div class="portal-stack portal-stack--tight">
          <h2 class="portal-heading portal-heading--card">
            {{ t('profile.legal') }}
          </h2>
          <p class="portal-copy portal-copy--small">
            {{ t('profile.legalDescription') }}
          </p>
        </div>

        <p
          v-if="hasRequiredConsentError"
          class="portal-notice portal-notice--error"
          role="alert"
        >
          {{ t('common.error') }}
        </p>

        <p
          v-if="props.legalDocuments.length === 0"
          class="portal-copy"
        >
          {{ t('profile.noDocuments') }}
        </p>
        <form
          v-else
          class="portal-stack"
          @submit.prevent="saveConsents"
        >
          <article
            v-for="(document, index) in props.legalDocuments"
            :key="document.id"
            class="portal-legal-card"
          >
            <div class="portal-section-heading">
              <h3 class="portal-heading portal-heading--card">
                {{ document.purpose }}
              </h3>
              <span
                v-if="document.isRequired"
                class="portal-copy portal-copy--small"
              >
                {{ t('profile.required') }}
              </span>
            </div>
            <div class="portal-legal-content">
              {{ document.content }}
            </div>
            <label class="portal-confirm">
              <input
                v-model="consentForm.consents[index].granted"
                type="checkbox"
                class="portal-checkbox"
              >
              <span>{{ t('profile.accept') }}</span>
            </label>
          </article>
          <button
            type="submit"
            class="portal-button portal-button--secondary self-start"
            :disabled="consentForm.processing"
          >
            {{ consentForm.processing ? t('profile.savingConsents') : t('profile.saveConsents') }}
          </button>
          <p
            v-if="props.consentsSaved"
            class="portal-notice"
            role="status"
          >
            {{ t('profile.consentsSaved') }}
          </p>
        </form>
      </section>
    </section>
  </AppShell>
</template>
