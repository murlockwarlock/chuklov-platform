<script setup lang="ts">
import { Link, router, useForm, useHttp } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import PatientIdentityFields from '../../Components/Portal/PatientIdentityFields.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import { shareTelegramLink } from '../../runtime/clientRuntime';
import { emptyPatientIdentity, type PatientSummary } from '../../types/patients';
import type { PortalShell } from '../../types/portal';

const props = defineProps<{ portal: PortalShell; patients: { data: PatientSummary[]; prev_page_url: string | null; next_page_url: string | null }; urls: { create: string; profile: string } }>();
const { t, locale } = usePortalLocale();
const familyMembers = computed(() => props.patients.data.filter((patient) => patient.relationship !== 'SELF'));
const form = useForm({ ...emptyPatientIdentity(), creation_key: crypto.randomUUID() });
const patientError = computed(() => Object.entries(form.errors).find(([key]) => key === 'patient')?.[1]);
const formError = ref('');
const dialog = ref<HTMLDialogElement | null>(null);
const editingPatient = ref<PatientSummary | null>(null);
const revokeDialog = ref<HTMLDialogElement | null>(null);
const revokePatient = ref<PatientSummary | null>(null);
const revokeProcessing = ref(false);
const revokeError = ref('');
const invite = useHttp<{ request_key: string }, { url: string; expiresAt: string }>({ request_key: '' });
const invitationUrl = ref('');
const invitationError = ref('');
const invitationStatus = ref('');
const copied = ref(false);
const inviteKeys = new Map<number, string>();

function create(): void {
    if (form.processing) return;
    formError.value = '';
    form.post(editingPatient.value?.updateUrl ?? props.urls.create, {
        preserveScroll: true,
        onSuccess: () => {
            dialog.value?.close();
            form.reset();
            editingPatient.value = null;
            form.creation_key = crypto.randomUUID();
            invitationUrl.value = '';
            inviteKeys.clear();
        },
        onError: (errors) => {
            formError.value = typeof errors.patient === 'string' ? errors.patient : '';
        },
        onHttpException: (response) => {
            formError.value = errorForStatus(response.status);

            return false;
        },
        onNetworkError: () => {
            formError.value = t('family.saveRetry');

            return false;
        },
    });
}

function updateIdentity(value: ReturnType<typeof emptyPatientIdentity>): void {
    const changedField = (Object.keys(value) as Array<keyof typeof value>).find((key) => form[key] !== value[key]);
    Object.assign(form, value);
    if (changedField) form.clearErrors(changedField);
    formError.value = '';
}

function errorForStatus(status: number): string {
    if (status === 401 || status === 419) return t('family.sessionExpired');
    if (status === 403) return t('family.accessDenied');
    if (status === 404 || status === 410) return t('family.objectUnavailable');

    return t('family.saveRetry');
}

function ageLabel(age: number): string {
    const form = new Intl.PluralRules(locale.value).select(age);

    return t('family.age.' + form, { count: age });
}

function safeErrorCode(data: unknown): string | null {
    if (typeof data !== 'string') return null;
    try {
        const payload = JSON.parse(data) as { safe_error_code?: unknown };

        return typeof payload.safe_error_code === 'string' ? payload.safe_error_code : null;
    } catch {
        return null;
    }
}

function openPatient(patient: PatientSummary | null = null): void {
    editingPatient.value = patient;
    formError.value = '';
    form.reset();
    form.clearErrors();
    if (patient) Object.assign(form, patient.identity);
    dialog.value?.showModal();
}

function issue(patient: PatientSummary): void {
    if (invite.processing) return;
    invitationUrl.value = '';
    invitationError.value = '';
    invitationStatus.value = '';
    copied.value = false;
    if (!inviteKeys.has(patient.id)) inviteKeys.set(patient.id, crypto.randomUUID());
    invite.request_key = inviteKeys.get(patient.id) ?? '';
    const failed = (): void => {
        if (!invitationError.value) invitationError.value = t('family.invitationFailed');
    };
    void invite.post(patient.invitationUrl, {
        onSuccess: (response) => {
            invitationUrl.value = response.url;
            invitationStatus.value = t('family.invitationReady');
        },
        onError: (errors) => {
            inviteKeys.delete(patient.id);
            invitationError.value = typeof errors.invitation === 'string' ? errors.invitation : t('family.invitationFailed');
        },
        onHttpException: (response) => {
            invitationError.value = response.status === 401 || response.status === 419
                ? t('family.sessionExpired')
                : response.status === 403
                    ? t('family.accessDenied')
                    : response.status === 503 && safeErrorCode(response.data) === 'telegram_bot_configuration_missing'
                        ? t('family.telegramUnavailable')
                        : t('family.invitationFailed');

            return false;
        },
        onNetworkError: () => {
            invitationError.value = t('family.invitationRetry');

            return false;
        },
    }).catch(failed);
}

async function copy(): Promise<void> {
    try { await navigator.clipboard.writeText(invitationUrl.value); copied.value = true; }
    catch { invitationError.value = t('family.copyFailed'); }
}

function askRevoke(patient: PatientSummary): void {
    revokePatient.value = patient;
    revokeError.value = '';
    revokeDialog.value?.showModal();
}

function revoke(): void {
    if (!revokePatient.value || revokeProcessing.value) return;
    revokeError.value = '';
    revokeProcessing.value = true;
    router.post(revokePatient.value.revokeUrl, {}, {
        preserveScroll: true,
        onSuccess: () => revokeDialog.value?.close(),
        onError: () => { revokeError.value = t('family.saveRetry'); },
        onHttpException: (response) => {
            revokeError.value = errorForStatus(response.status);

            return false;
        },
        onNetworkError: () => {
            revokeError.value = t('family.saveRetry');

            return false;
        },
        onFinish: () => { revokeProcessing.value = false; },
    });
}
</script>

<template>
  <AppShell
    :title="t('family.title')"
    :portal="props.portal"
  >
    <main class="portal-container portal-stack portal-stack--loose">
      <header class="portal-family-header">
        <h1 class="portal-heading">
          {{ t('family.title') }}
        </h1>
        <div class="portal-family-header__actions">
          <Link
            :href="props.urls.profile"
            class="portal-link"
          >
            {{ t('profile.title') }}
          </Link>
          <button
            type="button"
            class="portal-button portal-button--primary"
            @click="openPatient()"
          >
            {{ t('family.add') }}
          </button>
        </div>
      </header>
      <p class="portal-copy">
        {{ t('family.medicalPrivacy') }}
      </p>
      <div
        v-if="familyMembers.length"
        class="portal-stack"
      >
        <article
          v-for="patient in familyMembers"
          :key="patient.id"
          class="portal-family-card"
        >
          <div class="portal-family-card__identity">
            <h2 class="portal-heading portal-heading--section break-words">
              {{ patient.name }}
            </h2>
            <p class="portal-copy portal-copy--small">
              {{ patient.relationshipLabel }}<template v-if="patient.age !== null">
                · {{ ageLabel(patient.age) }}
              </template>
            </p>
          </div>
          <p
            v-if="patient.revoked"
            class="portal-copy"
          >
            {{ t('family.unlinked') }}
          </p>
          <div class="portal-family-card__actions">
            <button
              v-if="patient.canEdit"
              type="button"
              class="portal-link"
              @click="openPatient(patient)"
            >
              {{ t('medical.edit') }}
            </button>
            <Link
              v-if="patient.canBook"
              :href="patient.bookingUrl"
              class="portal-button portal-button--primary"
              :aria-label="t('family.bookFor', { name: patient.name })"
            >
              {{ t('family.book') }}
            </Link>
            <Link
              v-if="patient.medicalUrl"
              :href="patient.medicalUrl"
              class="portal-link"
            >
              {{ t('more.medicalProfile') }}
            </Link>
            <button
              v-if="patient.canInvite"
              type="button"
              :disabled="invite.processing"
              class="portal-button portal-button--secondary"
              @click="issue(patient)"
            >
              {{ invite.processing ? t('family.inviting') : t('family.invite') }}
            </button>
            <button
              v-if="patient.canRevoke"
              type="button"
              :disabled="revokeProcessing"
              class="portal-link"
              @click="askRevoke(patient)"
            >
              {{ t('family.unlink') }}
            </button>
          </div>
        </article>
      </div>
      <EmptyState
        v-else
        :title="t('family.empty')"
      />
      <div
        v-if="invitationStatus"
        class="portal-notice portal-notice--success"
        role="status"
        aria-live="polite"
      >
        {{ invitationStatus }}
      </div>
      <div
        v-if="invitationUrl"
        class="portal-stack"
      >
        <label
          for="family-invitation-url"
          class="portal-label"
        >{{ t('family.invitationLink') }}</label>
        <input
          id="family-invitation-url"
          :value="invitationUrl"
          readonly
          class="portal-input"
        >
        <div class="portal-form-actions">
          <button
            type="button"
            class="portal-button portal-button--primary"
            @click="shareTelegramLink(invitationUrl)"
          >
            {{ t('family.share') }}
          </button>
          <button
            type="button"
            class="portal-button portal-button--secondary"
            @click="copy"
          >
            {{ t(copied ? 'family.copied' : 'family.copy') }}
          </button>
        </div>
      </div>
      <p
        v-if="invitationError"
        class="portal-error"
        role="alert"
      >
        {{ invitationError }}
      </p>
      <nav
        class="portal-form-actions"
        :aria-label="t('family.pages')"
      >
        <Link
          v-if="props.patients.prev_page_url"
          :href="props.patients.prev_page_url"
          class="portal-button portal-button--secondary"
        >
          {{ t('family.previous') }}
        </Link>
        <Link
          v-if="props.patients.next_page_url"
          :href="props.patients.next_page_url"
          class="portal-button portal-button--secondary"
        >
          {{ t('family.next') }}
        </Link>
      </nav>
      <dialog
        ref="dialog"
        class="portal-dialog"
      >
        <form
          class="portal-stack"
          novalidate
          @submit.prevent="create"
        >
          <h2 class="portal-heading portal-heading--section">
            {{ t(editingPatient ? 'medical.edit' : 'family.add') }}
          </h2>
          <PatientIdentityFields
            :model-value="form.data()"
            id-prefix="family-new"
            :errors="form.errors"
            @update:model-value="updateIdentity"
          />
          <p
            v-if="patientError"
            class="portal-error"
            role="alert"
          >
            {{ patientError }}
          </p>
          <p
            v-if="formError"
            class="portal-error"
            role="alert"
            aria-live="assertive"
          >
            {{ formError }}
          </p>
          <p
            v-if="form.errors.creation_key"
            class="portal-error"
          >
            {{ form.errors.creation_key }}
          </p>
          <div class="portal-form-actions">
            <button
              type="submit"
              :disabled="form.processing"
              class="portal-button portal-button--primary"
            >
              {{ form.processing ? t('profile.saving') : t('profile.save') }}
            </button>
            <button
              type="button"
              class="portal-button portal-button--secondary"
              @click="dialog?.close()"
            >
              {{ t('family.close') }}
            </button>
          </div>
        </form>
      </dialog>
      <dialog
        ref="revokeDialog"
        class="portal-dialog"
      >
        <form
          class="portal-stack"
          novalidate
          @submit.prevent="revoke"
        >
          <h2 class="portal-heading portal-heading--section">
            {{ t('family.unlink') }}
          </h2>
          <p class="portal-copy">
            {{ t('family.unlinkConfirmation') }}
          </p>
          <p
            v-if="revokeError"
            class="portal-error"
            role="alert"
            aria-live="assertive"
          >
            {{ revokeError }}
          </p>
          <div class="portal-form-actions">
            <button
              type="submit"
              :disabled="revokeProcessing"
              class="portal-button portal-button--primary"
            >
              {{ revokeProcessing ? t('family.unlinking') : t('family.unlink') }}
            </button>
            <button
              type="button"
              class="portal-button portal-button--secondary"
              @click="revokeDialog?.close()"
            >
              {{ t('family.close') }}
            </button>
          </div>
        </form>
      </dialog>
    </main>
  </AppShell>
</template>

<style scoped>
.portal-family-header {
    display: flex;
    min-width: 0;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--portal-space-4);
}

.portal-family-header__actions {
    display: flex;
    min-width: 0;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: var(--portal-space-3);
}

.portal-family-card {
    display: grid;
    min-width: 0;
    gap: var(--portal-space-3);
    border: 1px solid var(--portal-color-border);
    border-radius: var(--portal-radius-lg);
    background: var(--portal-color-surface);
    padding: var(--portal-space-4);
    box-shadow: var(--portal-shadow-card);
}

.portal-family-card__identity {
    display: grid;
    min-width: 0;
    gap: 0.25rem;
}

.portal-family-card__identity h2 {
    margin: 0;
    font-size: 1.15rem;
    line-height: 1.3;
}

.portal-family-card__actions {
    display: flex;
    min-width: 0;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--portal-space-2) var(--portal-space-3);
}

@media (max-width: 40rem) {
    .portal-family-header__actions {
        width: 100%;
        justify-content: space-between;
    }

    .portal-family-header__actions > .portal-button {
        flex: 1 1 auto;
    }
}
</style>
