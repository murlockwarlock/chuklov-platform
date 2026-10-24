<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import LegalConsentChecklist from '../../Components/Portal/LegalConsentChecklist.vue';
import TextAutocomplete from '../../Components/Portal/TextAutocomplete.vue';
import CityAutocomplete from '../../Components/Portal/CityAutocomplete.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';
import { countryNames } from '../../utils/countryNames';
import { formatPhone, formatPhoneInput, phoneExample } from '../../utils/profilePhone';

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
    firstName: string | null;
    lastName: string | null;
    middleName: string | null;
    gender: 'MALE' | 'FEMALE' | null;
    birthDate: string | null;
    country: string | null;
    city: string | null;
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
        family: string | null;
        update: string;
        consents: string;
        telegramLink: string;
        b2bAnswer: string;
        referrals: string;
        cities: string;
        currency: string;
    };
    saved: boolean;
    currencySaved: boolean;
    currencyPreference: { selected: string | null; base: string | null; options: string[] };
    consentsSaved: boolean;
    b2bSpecialistAnswer: 'yes' | 'no' | null;
    marketingConsent: { documentId: number; accepted: boolean } | null;
    timezoneOptions: Array<{ value: string; label: string }>;
}>();

const { locale, t } = usePortalLocale();
const profileSaved = ref(props.saved);
const profileSaveError = ref<string | null>(null);
const currencyForm = useForm({ preferred_currency: props.currencyPreference.selected ?? '' });
const profileForm = useForm<{
    first_name: string;
    last_name: string;
    middle_name: string;
    gender: string;
    birth_date: string;
    country: string;
    city: string;
    email: string;
    phone: string;
    timezone: string;
}>({
    first_name: props.profile.firstName ?? '',
    last_name: props.profile.lastName ?? '',
    middle_name: props.profile.middleName ?? '',
    gender: props.profile.gender ?? '',
    birth_date: props.profile.birthDate ?? '',
    country: props.profile.country ?? '',
    city: props.profile.city ?? '',
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
const personalFields = [
    { key: 'last_name', label: 'profile.lastName', autocomplete: 'family-name', required: true },
    { key: 'first_name', label: 'profile.firstName', autocomplete: 'given-name', required: true },
    { key: 'middle_name', label: 'profile.middleName', autocomplete: 'additional-name', required: false },
    { key: 'country', label: 'profile.country', autocomplete: 'country-name', required: true },
    { key: 'city', label: 'profile.city', autocomplete: 'address-level2', required: true },
] as const;
const countries = computed(() => countryNames(locale.value));
const hasRequiredConsentError = computed(() =>
    Object.keys(consentForm.errors).some((key) => key.startsWith('consents')),
);

function saveProfile(): void {
    profileForm.post(props.urls.update, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            profileSaved.value = false;
            profileSaveError.value = null;
        },
        onSuccess: () => {
            profileSaved.value = true;
        },
        onHttpException: (response) => {
            profileSaveError.value = response.status === 401 || response.status === 419
                ? t('profile.saveSessionExpired')
                : response.status === 403
                    ? t('profile.saveAccessDenied')
                    : t('profile.saveRetry');

            return false;
        },
        onNetworkError: () => {
            profileSaveError.value = t('profile.saveRetry');

            return false;
        },
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

function setRequiredConsent(granted: boolean): void {
    props.legalDocuments
        .filter((document) => document.isRequired)
        .forEach((document) => setConsent(document.id, granted));
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
    active="more"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <h1 class="portal-heading portal-heading--section">
            {{ t('profile.title') }}
          </h1>
        </div>
      </header>

      <form
        v-if="props.currencyPreference.options.length"
        class="portal-content-section portal-stack"
        @submit.prevent="currencyForm.post(props.urls.currency, { preserveScroll: true })"
      >
        <div class="portal-field">
          <label
            for="profile-currency"
            class="portal-label"
          >{{ t('profile.currency') }}</label>
          <select
            id="profile-currency"
            v-model="currencyForm.preferred_currency"
            class="portal-input"
          >
            <option value="">
              {{ t('profile.currencyDefault') }} ({{ props.currencyPreference.base }})
            </option>
            <option
              v-for="currency in props.currencyPreference.options"
              :key="currency"
              :value="currency"
            >
              {{ currency }}
            </option>
          </select>
          <p
            v-if="currencyForm.errors.preferred_currency"
            class="portal-error"
          >
            {{ currencyForm.errors.preferred_currency }}
          </p>
        </div>
        <div class="portal-form-actions">
          <button
            type="submit"
            class="portal-button portal-button--primary"
            :disabled="currencyForm.processing"
          >
            {{ t('profile.save') }}
          </button>
          <p
            v-if="currencySaved"
            class="portal-copy"
          >
            {{ t('profile.saved') }}
          </p>
        </div>
      </form>

      <Link
        v-if="props.urls.family"
        :href="props.urls.family"
        class="portal-link"
      >
        {{ t('family.title') }}
      </Link>
      <section class="portal-content-section portal-stack">
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
            <div
              v-for="field in personalFields"
              :key="field.key"
              class="portal-field"
            >
              <label
                :for="'profile-' + field.key"
                class="portal-label"
              >{{ t(field.label) }}</label>
              <TextAutocomplete
                v-if="field.key === 'country'"
                :id="'profile-' + field.key"
                v-model="profileForm.country"
                :suggestions="countries"
                :empty-text="t('profile.countryNoMatch')"
                required
              />
              <input
                v-else-if="field.key !== 'city'"
                :id="'profile-' + field.key"
                v-model="profileForm[field.key]"
                type="text"
                :autocomplete="field.autocomplete"
                :required="field.required"
                maxlength="100"
                class="portal-input"
              >
              <CityAutocomplete
                v-else
                :id="'profile-' + field.key"
                v-model="profileForm.city"
                :country="profileForm.country"
                :cities-url="props.urls.cities"
                required
              />
              <p
                v-if="profileForm.errors[field.key]"
                class="portal-error"
              >
                {{ profileForm.errors[field.key] }}
              </p>
            </div>

            <fieldset class="portal-field">
              <legend class="portal-label">
                {{ t('profile.gender') }}
              </legend>
              <div class="portal-segmented portal-profile-gender">
                <label
                  v-for="gender in ['MALE', 'FEMALE']"
                  :key="gender"
                  class="portal-segmented__button portal-profile-gender__option"
                  :class="{ 'portal-segmented__button--active': profileForm.gender === gender }"
                >
                  <input
                    v-model="profileForm.gender"
                    type="radio"
                    name="gender"
                    :value="gender"
                    required
                    class="sr-only"
                  >
                  {{ t(gender === 'MALE' ? 'profile.male' : 'profile.female') }}
                </label>
              </div>
              <p
                v-if="profileForm.errors.gender"
                class="portal-error"
              >
                {{ profileForm.errors.gender }}
              </p>
            </fieldset>
            <div class="portal-field">
              <label
                for="profile-birth-date"
                class="portal-label"
              >{{ t('profile.birthDate') }}</label>
              <input
                id="profile-birth-date"
                v-model="profileForm.birth_date"
                type="date"
                required
                class="portal-input"
              >
              <p
                v-if="profileForm.errors.birth_date"
                class="portal-error"
              >
                {{ profileForm.errors.birth_date }}
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
                required
                autocomplete="tel"
                class="portal-input"
                :placeholder="phoneExample(profileForm.country)"
                maxlength="32"
                @input="profileForm.phone = formatPhoneInput(profileForm.phone, profileForm.country)"
                @blur="profileForm.phone = formatPhone(profileForm.phone, profileForm.country)"
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
              <p class="portal-copy portal-copy--small">
                {{ t('profile.emailPurpose') }}
              </p>
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
              v-if="profileSaved"
              class="portal-notice"
              role="status"
            >
              {{ t('profile.saved') }}
            </p>
            <p
              v-if="profileSaveError"
              class="portal-error"
              role="alert"
            >
              {{ profileSaveError }}
            </p>
          </div>
        </form>
      </section>

      <section class="portal-content-section portal-stack">
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

      <section class="portal-content-section portal-stack">
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

      <section class="portal-content-section portal-stack">
        <div class="portal-stack portal-stack--tight">
          <h2 class="portal-heading portal-heading--card">
            {{ t('profile.legal') }}
          </h2>
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
            group-required-acceptance
            @change="setConsent"
            @required-change="setRequiredConsent"
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
    </section>
  </AppShell>
</template>

<style scoped>
.portal-profile-gender {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.portal-profile-gender__option {
    display: grid;
    place-items: center;
    text-align: center;
}

.portal-profile-gender__option:focus-within {
    outline: 2px solid var(--portal-color-ink);
    outline-offset: 2px;
}
</style>
