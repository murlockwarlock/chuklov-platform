<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
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

const props = defineProps<{
    portal: PortalShell;
    profile: Profile;
    legalDocuments: LegalDocument[];
    urls: {
        family: string | null;
        update: string;
        consents: string;
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
const profileSaveError = ref<string | null>(null);
const profileSaveState = ref<'idle' | 'saving' | 'saved' | 'error'>(props.saved ? 'saved' : 'idle');
const profileFieldErrors = ref<Record<string, string>>({});
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
const b2bAnswerForm = useForm<{ b2b_specialist_answer: 'yes' | 'no' | null }>({
    b2b_specialist_answer: props.b2bSpecialistAnswer,
});
const editingB2bAnswer = ref(false);
const personalFields = [
    { key: 'last_name', label: 'profile.lastName', autocomplete: 'family-name' },
    { key: 'first_name', label: 'profile.firstName', autocomplete: 'given-name' },
    { key: 'middle_name', label: 'profile.middleName', autocomplete: 'additional-name' },
    { key: 'country', label: 'profile.country', autocomplete: 'country-name' },
    { key: 'city', label: 'profile.city', autocomplete: 'address-level2' },
] as const;
type PersonalField = typeof personalFields[number]['key'] | 'gender' | 'birth_date' | 'phone' | 'email' | 'timezone';
const failedProfileFields = ref<PersonalField[]>([]);
const pendingProfileFields = new Set<PersonalField>();
let profileSaveTimer: ReturnType<typeof setTimeout> | null = null;
let profileSaveInFlight = false;
let lastProfileFields: PersonalField[] = [];
const countries = computed(() => countryNames(locale.value));
const hasRequiredConsentError = computed(() =>
    Object.keys(consentForm.errors).some((key) => key.startsWith('consents')),
);

function profileValue(field: PersonalField): string | null {
    const value = profileForm[field];

    return value === '' ? null : value;
}

function scheduleProfileSave(): void {
    if (profileSaveTimer) {
        clearTimeout(profileSaveTimer);
    }

    profileSaveTimer = setTimeout(() => {
        profileSaveTimer = null;
        flushProfileSave();
    }, 450);
}

function queueProfileField(field: PersonalField): void {
    pendingProfileFields.add(field);
    profileFieldErrors.value[field] = '';
    profileSaveError.value = null;
    failedProfileFields.value = [];
    profileSaveState.value = 'idle';
    scheduleProfileSave();
}

function normalizeProfileErrors(errors: Record<string, string | string[]>): Record<string, string> {
    return Object.fromEntries(Object.entries(errors).map(([field, message]) => [
        field,
        Array.isArray(message) ? (message[0] ?? '') : message,
    ]));
}

function transportErrorMessage(status?: number): string {
    return status === 401 || status === 419
        ? t('profile.saveSessionExpired')
        : status === 403
            ? t('profile.saveAccessDenied')
            : t('profile.saveRetry');
}

function flushProfileSave(): void {
    if (profileSaveInFlight || pendingProfileFields.size === 0) {
        return;
    }

    const field = pendingProfileFields.values().next().value as PersonalField | undefined;
    if (!field) {
        return;
    }

    const fields = [field];
    pendingProfileFields.delete(field);
    lastProfileFields = fields;
    profileSaveInFlight = true;
    profileSaveState.value = 'saving';
    profileSaveError.value = null;

    const payload = Object.fromEntries(fields.map((field) => [field, profileValue(field)]));
    router.post(props.urls.update, payload, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            profileSaveError.value = null;
            failedProfileFields.value = failedProfileFields.value.filter((failedField) => failedField !== field);
            profileSaveState.value = failedProfileFields.value.length ? 'error' : 'saved';
        },
        onError: (errors) => {
            const normalized = normalizeProfileErrors(errors);
            profileFieldErrors.value = { ...profileFieldErrors.value, ...normalized };
            failedProfileFields.value = Array.from(new Set([
                ...failedProfileFields.value,
                ...fields.filter((field) => Boolean(normalized[field])),
            ]));
            profileSaveState.value = 'error';
            profileSaveError.value = Object.keys(normalized).length ? null : t('profile.saveRetry');
        },
        onHttpException: (response) => {
            profileSaveState.value = 'error';
            profileSaveError.value = transportErrorMessage(response.status);
            failedProfileFields.value = Array.from(new Set([
                ...failedProfileFields.value,
                ...fields,
                ...pendingProfileFields,
            ]));
            pendingProfileFields.clear();

            return false;
        },
        onNetworkError: () => {
            profileSaveState.value = 'error';
            profileSaveError.value = t('profile.saveRetry');
            failedProfileFields.value = Array.from(new Set([
                ...failedProfileFields.value,
                ...fields,
                ...pendingProfileFields,
            ]));
            pendingProfileFields.clear();

            return false;
        },
        onFinish: () => {
            profileSaveInFlight = false;
            if (pendingProfileFields.size > 0) {
                scheduleProfileSave();
            }
        },
    });
}

function retryProfileSave(): void {
    const fields = failedProfileFields.value.length ? failedProfileFields.value : lastProfileFields;
    fields.forEach((field) => pendingProfileFields.add(field));
    profileSaveError.value = null;
    scheduleProfileSave();
}

onBeforeUnmount(() => {
    if (profileSaveTimer) {
        clearTimeout(profileSaveTimer);
    }
});

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
        <p class="portal-copy portal-copy--small">
          {{ t('profile.personalHelp') }}
        </p>
        <div
          class="portal-stack"
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
                @blur="queueProfileField(field.key)"
              />
              <input
                v-else-if="field.key !== 'city'"
                :id="'profile-' + field.key"
                v-model="profileForm[field.key]"
                type="text"
                :autocomplete="field.autocomplete"
                maxlength="100"
                class="portal-input"
                @blur="queueProfileField(field.key)"
              >
              <CityAutocomplete
                v-else
                :id="'profile-' + field.key"
                v-model="profileForm.city"
                :country="profileForm.country"
                :cities-url="props.urls.cities"
                @blur="queueProfileField(field.key)"
              />
              <p
                v-if="profileFieldErrors[field.key]"
                class="portal-error"
              >
                {{ profileFieldErrors[field.key] }}
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
                    class="sr-only"
                    @change="queueProfileField('gender')"
                  >
                  {{ t(gender === 'MALE' ? 'profile.male' : 'profile.female') }}
                </label>
              </div>
              <p
                v-if="profileFieldErrors.gender"
                class="portal-error"
              >
                {{ profileFieldErrors.gender }}
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
                class="portal-input"
                @blur="queueProfileField('birth_date')"
              >
              <p
                v-if="profileFieldErrors.birth_date"
                class="portal-error"
              >
                {{ profileFieldErrors.birth_date }}
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
                :placeholder="phoneExample(profileForm.country)"
                maxlength="32"
                @input="profileForm.phone = formatPhoneInput(profileForm.phone, profileForm.country)"
                @blur="profileForm.phone = formatPhone(profileForm.phone, profileForm.country); queueProfileField('phone')"
              >
              <p
                v-if="profileFieldErrors.phone"
                class="portal-error"
              >
                {{ profileFieldErrors.phone }}
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
                @blur="queueProfileField('email')"
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
                v-if="profileFieldErrors.email"
                class="portal-error"
              >
                {{ profileFieldErrors.email }}
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
                @change="queueProfileField('timezone')"
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
            <p
              v-if="profileSaveState === 'saving'"
              class="portal-notice"
              role="status"
            >
              {{ t('profile.saving') }}
            </p>
            <p
              v-else-if="profileSaveState === 'saved'"
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
            <button
              v-if="profileSaveState === 'error'"
              type="button"
              class="portal-button portal-button--secondary"
              @click="retryProfileSave"
            >
              {{ t('profile.retry') }}
            </button>
          </div>
        </div>
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
