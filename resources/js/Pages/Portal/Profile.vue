<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import LegalConsentChecklist from '../../Components/Portal/LegalConsentChecklist.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type LegalDocument = {
    id: number;
    documentType: string;
    purpose: string;
    content: string;
    contentHtml: string;
    title: string;
    version: string;
    isRequired: boolean;
    accepted: boolean;
};

type Profile = {
    fullName: string | null;
    email: string | null;
    phone: string | null;
    timezone: string;
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
        b2bAnswer: string;
        referrals: string;
    };
    saved: boolean;
    consentsSaved: boolean;
    b2bSpecialistAnswer: 'yes' | 'no' | null;
    marketingConsent: { documentId: number; accepted: boolean } | null;
    timezoneOptions: Array<{ value: string; label: string }>;
}>();

const { locale, t } = usePortalLocale();
const profileForm = useForm<{
    full_name: string;
    email: string;
    phone: string;
    timezone: string;
}>({
    full_name: props.profile.fullName ?? '',
    email: props.profile.email ?? '',
    phone: props.profile.phone ?? '',
    timezone: props.profile.timezone,
});
const consentForm = useForm<{
    consents: Array<{ legal_document_id: number; granted: boolean }>;
    marketing_consent: boolean;
}>({
    consents: props.legalDocuments.filter((document) => document.isRequired).map((document) => ({
        legal_document_id: document.id,
        granted: document.accepted,
    })),
    marketing_consent: props.marketingConsent?.accepted ?? false,
});
const consentValues = ref<Record<number, boolean>>(Object.fromEntries(
    consentForm.consents.map((consent) => [consent.legal_document_id, consent.granted]),
));
const telegramLinkForm = useForm<Record<string, never>>({});
const b2bAnswerForm = useForm<{ b2b_specialist_answer: 'yes' | 'no' | null }>({
    b2b_specialist_answer: props.b2bSpecialistAnswer,
});
const editingB2bAnswer = ref(false);
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

function setConsent(id: number, granted: boolean): void {
    consentValues.value[id] = granted;
    const consent = consentForm.consents.find((item) => item.legal_document_id === id);
    if (consent) {
        consent.granted = granted;
    }
}

function setMarketingConsent(granted: boolean): void {
    consentForm.marketing_consent = granted;
}

function requestTelegramLink(): void {
    telegramLinkForm.post(props.urls.telegramLink, {
        preserveScroll: true,
    });
}

function saveB2bAnswer(): void {
    b2bAnswerForm.post(props.urls.b2bAnswer, {
        preserveScroll: true,
        onSuccess: () => {
            editingB2bAnswer.value = false;
        },
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
            <div class="portal-field portal-field--wide">
              <label
                for="profile-timezone"
                class="portal-label"
              >{{ t('profile.timezone') }}</label>
              <select
                id="profile-timezone"
                v-model="profileForm.timezone"
                class="portal-input"
              >
                <option
                  v-for="option in props.timezoneOptions"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </option>
              </select>
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
              {{ t('profile.professional') }}
            </h2>
            <p class="portal-copy portal-copy--small">
              {{ props.b2bSpecialistAnswer === 'yes'
                ? t('profile.b2bYes')
                : props.b2bSpecialistAnswer === 'no'
                  ? t('profile.b2bNo')
                  : t('profile.b2bMissing') }}
            </p>
          </div>
          <button
            type="button"
            class="portal-link portal-link--button"
            @click="editingB2bAnswer = true"
          >
            {{ props.b2bSpecialistAnswer === null ? t('profile.setAnswer') : t('profile.edit') }}
          </button>
        </div>
        <form
          v-if="editingB2bAnswer"
          class="portal-stack portal-stack--tight"
          @submit.prevent="saveB2bAnswer"
        >
          <p class="portal-copy portal-copy--small">
            {{ t('b2b.question') }}
          </p>
          <label class="portal-confirm">
            <input
              v-model="b2bAnswerForm.b2b_specialist_answer"
              type="radio"
              value="yes"
              class="portal-checkbox"
            >
            <span>{{ t('b2b.yes') }}</span>
          </label>
          <label class="portal-confirm">
            <input
              v-model="b2bAnswerForm.b2b_specialist_answer"
              type="radio"
              value="no"
              class="portal-checkbox"
            >
            <span>{{ t('b2b.no') }}</span>
          </label>
          <button
            type="submit"
            class="portal-button portal-button--secondary self-start"
            :disabled="b2bAnswerForm.processing"
          >
            {{ b2bAnswerForm.processing ? t('profile.saving') : t('profile.save') }}
          </button>
          <p
            v-if="b2bAnswerForm.errors.b2b_specialist_answer"
            class="portal-error"
          >
            {{ b2bAnswerForm.errors.b2b_specialist_answer }}
          </p>
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
          <LegalConsentChecklist
            :documents="props.legalDocuments"
            :values="consentValues"
            :marketing-value="consentForm.marketing_consent"
            :show-marketing="props.marketingConsent !== null"
            @change="setConsent"
            @update:marketing-value="setMarketingConsent"
          />
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

      <section class="portal-panel portal-stack portal-stack--tight">
        <h2 class="portal-heading portal-heading--card">
          {{ t('home.referrals') }}
        </h2>
        <p class="portal-copy portal-copy--small">
          {{ t('home.referralsDescription') }}
        </p>
        <Link
          :href="props.portal.urls.referrals"
          class="portal-button portal-button--secondary self-start"
        >
          {{ t('home.referrals') }}
        </Link>
      </section>
    </section>
  </AppShell>
</template>
