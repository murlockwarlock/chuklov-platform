<script setup lang="ts">
import { Link, router, useForm, useHttp } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import PatientIdentityFields from '../../Components/Portal/PatientIdentityFields.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import { shareTelegramLink } from '../../runtime/clientRuntime';
import { emptyPatientIdentity, type PatientSummary } from '../../types/patients';
import type { PortalShell } from '../../types/portal';

const props = defineProps<{ portal: PortalShell; patients: { data: PatientSummary[]; prev_page_url: string | null; next_page_url: string | null }; urls: { create: string; profile: string } }>();
const { t } = usePortalLocale();
const form = useForm({ ...emptyPatientIdentity(), creation_key: crypto.randomUUID() });
const patientError = computed(() => Object.entries(form.errors).find(([key]) => key === 'patient')?.[1]);
const dialog = ref<HTMLDialogElement | null>(null);
const editingPatient = ref<PatientSummary | null>(null);
const revokeDialog = ref<HTMLDialogElement | null>(null);
const revokePatient = ref<PatientSummary | null>(null);
const revokeProcessing = ref(false);
const invite = useHttp<{ request_key: string }, { url: string; expiresAt: string }>({ request_key: '' });
const invitationUrl = ref('');
const invitationError = ref('');
const copied = ref(false);
const inviteKeys = new Map<number, string>();

function create(): void {
    form.post(editingPatient.value?.updateUrl ?? props.urls.create, { preserveScroll: true, onSuccess: () => { dialog.value?.close(); form.reset(); editingPatient.value = null; form.creation_key = crypto.randomUUID(); invitationUrl.value = ''; inviteKeys.clear(); } });
}

function openPatient(patient: PatientSummary | null = null): void {
    editingPatient.value = patient;
    form.reset();
    form.clearErrors();
    if (patient) Object.assign(form, patient.identity);
    dialog.value?.showModal();
}

function issue(patient: PatientSummary): void {
    if (invite.processing) return;
    invitationUrl.value = '';
    invitationError.value = '';
    copied.value = false;
    if (!inviteKeys.has(patient.id)) inviteKeys.set(patient.id, crypto.randomUUID());
    invite.request_key = inviteKeys.get(patient.id) ?? '';
    const failed = (): void => { invitationError.value = t('family.invitationFailed'); };
    void invite.post(patient.invitationUrl, {
        onSuccess: (response) => { invitationUrl.value = response.url; },
        onError: () => { inviteKeys.delete(patient.id); failed(); }, onHttpException: failed, onNetworkError: failed,
    }).catch(failed);
}

async function copy(): Promise<void> {
    try { await navigator.clipboard.writeText(invitationUrl.value); copied.value = true; }
    catch { invitationError.value = t('family.copyFailed'); }
}

function askRevoke(patient: PatientSummary): void {
    revokePatient.value = patient;
    revokeDialog.value?.showModal();
}

function revoke(): void {
    if (!revokePatient.value || revokeProcessing.value) return;
    revokeProcessing.value = true;
    router.post(revokePatient.value.revokeUrl, {}, { preserveScroll: true, onSuccess: () => revokeDialog.value?.close(), onFinish: () => { revokeProcessing.value = false; } });
}
</script>

<template>
  <AppShell
    :title="t('family.title')"
    :portal="props.portal"
  >
    <main class="portal-container portal-stack portal-stack--loose">
      <header class="portal-section-header">
        <h1 class="portal-heading">
          {{ t('family.title') }}
        </h1>
        <Link
          :href="props.urls.profile"
          class="portal-link"
        >
          {{ t('profile.title') }}
        </Link>
      </header>
      <button
        type="button"
        class="portal-button portal-button--primary"
        @click="openPatient()"
      >
        {{ t('family.add') }}
      </button>
      <p class="portal-copy">
        {{ t('family.medicalPrivacy') }}
      </p>
      <div class="portal-stack">
        <article
          v-for="patient in props.patients.data"
          :key="patient.id"
          class="portal-content-section portal-stack portal-stack--tight"
        >
          <h2 class="portal-heading portal-heading--section break-words">
            {{ patient.name }}
          </h2>
          <p class="portal-copy">
            {{ patient.relationshipLabel }}<template v-if="patient.age !== null">
              · {{ t('family.age', { count: patient.age }) }}
            </template>
          </p>
          <p
            v-if="patient.revoked"
            class="portal-copy"
          >
            {{ t('family.unlinked') }}
          </p>
          <div class="portal-form-actions">
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
              class="portal-button portal-button--secondary"
            >
              {{ t('shell.book') }}
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
              {{ t('family.invite') }}
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
          @submit.prevent="create"
        >
          <h2 class="portal-heading portal-heading--section">
            {{ t(editingPatient ? 'medical.edit' : 'family.add') }}
          </h2>
          <PatientIdentityFields
            :model-value="form.data()"
            id-prefix="family-new"
            :errors="form.errors"
            @update:model-value="Object.assign(form, $event)"
          />
          <p
            v-if="patientError"
            class="portal-error"
            role="alert"
          >
            {{ patientError }}
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
              {{ t('profile.save') }}
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
          @submit.prevent="revoke"
        >
          <h2 class="portal-heading portal-heading--section">
            {{ t('family.unlink') }}
          </h2>
          <p class="portal-copy">
            {{ t('family.unlinkConfirmation') }}
          </p>
          <div class="portal-form-actions">
            <button
              type="submit"
              :disabled="revokeProcessing"
              class="portal-button portal-button--primary"
            >
              {{ t('family.unlink') }}
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
