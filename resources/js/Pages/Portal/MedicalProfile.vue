<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import PainScale from '../../Components/Portal/PainScale.vue';
import MedicalAttachmentsUploader from '../../Components/Portal/MedicalAttachmentsUploader.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Answers = {
    symptoms: string[];
    pain_vas: number | null;
    no_chronic_conditions: boolean;
    had_operations: boolean | null;
    operation_year: number | null;
    had_injuries: boolean | null;
    injury_year: number | null;
};
const props = defineProps<{
    portal: PortalShell;
    profile: { available: boolean; readFailed: boolean; expectedSnapshot: string; fields: Record<string, string | null>; answers: Partial<Answers>; legacyFields: Record<string, string> };
    urls: { update: string; upload: string };
    materials: { id: number; filename: string; downloadUrl: string }[];
    saved: boolean;
    editing: boolean;
    returnUrl: string;
    returnLabelKey: string;
}>();
const { t } = usePortalLocale();
const editing = ref(props.editing || !props.profile.available);
const uploading = ref(false);
const currentYear = new Date().getFullYear();
const requestError = ref('');
const clientErrors = ref<Record<string, string>>({});
const medicalForm = ref<HTMLFormElement | null>(null);
const fields = ['complaints', 'goals', 'chronic_conditions', 'operations', 'injuries', 'medicines', 'supplements'] as const;
const symptoms = ['headache', 'neck', 'back', 'joints', 'swelling', 'digestion', 'weakness', 'insomnia', 'stress'];
const form = useForm({
    expected_snapshot: props.profile.expectedSnapshot,
    complaints: props.profile.fields.complaints ?? '',
    goals: props.profile.fields.goals ?? '',
    chronic_conditions: props.profile.fields.chronic_conditions ?? '',
    operations: props.profile.fields.operations ?? '',
    injuries: props.profile.fields.injuries ?? '',
    medicines: props.profile.fields.medicines ?? '',
    supplements: props.profile.fields.supplements ?? '',
    answers: {
        symptoms: [], pain_vas: null, no_chronic_conditions: false,
        had_operations: null, operation_year: null, had_injuries: null, injury_year: null,
        ...props.profile.answers,
    } as Answers,
});
function clearClientError(...keys: string[]): void {
    for (const key of keys) {
        delete clientErrors.value[key];
    }
}

function validate(): string | null {
    const answers = form.answers;

    if (!form.goals.trim()) {
        clientErrors.value.goals = t('medical.goalRequired');
    }
    if (answers.symptoms.length === 0 && !form.complaints.trim()) {
        clientErrors.value.complaints = t('medical.complaintsRequired');
    }

    for (const field of ['operations', 'injuries'] as const) {
        const answer = field === 'operations' ? answers.had_operations : answers.had_injuries;
        const year = field === 'operations' ? answers.operation_year : answers.injury_year;
        const yearField = field === 'operations' ? 'operation_year' : 'injury_year';
        const answerField = field === 'operations' ? 'had_operations' : 'had_injuries';

        if (answer === null) {
            clientErrors.value[answerField] = t('medical.answerRequired');
            continue;
        }
        if (!answer) {
            continue;
        }
        if (typeof year !== 'number') {
            clientErrors.value[yearField] = t('medical.yearRequired');
        } else if (!Number.isInteger(year) || year < 1900 || year > currentYear) {
            clientErrors.value[yearField] = t('medical.yearInvalid', { year: currentYear });
        }
        if (!form[field].trim()) {
            clientErrors.value[field] = t('medical.detailsRequired');
        }
    }

    const firstInvalid = ['goals', 'complaints', 'had_operations', 'operation_year', 'operations', 'had_injuries', 'injury_year', 'injuries']
        .find((field) => clientErrors.value[field]);

    return firstInvalid ?? null;
}

function save(): void {
    if (form.processing || uploading.value) {
        return;
    }
    clientErrors.value = {};
    requestError.value = '';
    const firstInvalid = validate();
    if (firstInvalid) {
        const fieldTargets: Record<string, string> = {
            goals: '#medical-goals',
            complaints: '[data-medical-field="complaints"] input',
            had_operations: 'input[name="operations"]',
            operation_year: '#medical-operation-year',
            operations: '#medical-operations',
            had_injuries: 'input[name="injuries"]',
            injury_year: '#medical-injury-year',
            injuries: '#medical-injuries',
        };
        medicalForm.value?.querySelector<HTMLElement>(fieldTargets[firstInvalid])?.focus();
        return;
    }

    form.post(props.urls.update, {
        preserveScroll: true,
        onSuccess: () => {
            form.expected_snapshot = props.profile.expectedSnapshot;
            form.defaults();
            editing.value = false;
        },
        onNetworkError: () => {
            requestError.value = t('medical.saveNetworkError');
            return false;
        },
        onHttpException: (response) => {
            requestError.value = response.status === 401 || response.status === 419
                ? t('medical.saveSessionExpired')
                : response.status === 403
                    ? t('medical.saveForbidden')
                    : t('medical.saveFailed');
            return false;
        },
    });
}
</script>

<template>
  <AppShell
    :title="t('medical.title')"
    :portal="portal"
    active="more"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <h1 class="portal-heading portal-heading--section">
        {{ t('medical.title') }}
      </h1>
      <p
        v-if="profile.readFailed"
        class="portal-error"
      >
        {{ t('medical.readError') }}
      </p>
      <template v-else>
        <dl
          v-if="Object.keys(profile.legacyFields).length"
          class="portal-stack"
        >
          <div
            v-for="(value, key) in profile.legacyFields"
            :key="key"
          >
            <dt class="portal-label">
              {{ t('health.' + key) }}
            </dt>
            <dd class="portal-copy whitespace-pre-wrap break-words">
              {{ value }}
            </dd>
          </div>
        </dl>
        <p
          v-if="saved"
          role="status"
          class="portal-copy"
        >
          {{ t('medical.saved') }}
        </p>
        <p
          v-if="profile.available && editing"
          class="portal-copy"
        >
          {{ t('medical.prefill') }}
        </p>
        <form
          v-if="editing"
          ref="medicalForm"
          class="portal-stack"
          novalidate
          @submit.prevent="save"
        >
          <fieldset
            class="portal-stack min-w-0"
            data-medical-field="complaints"
          >
            <legend class="portal-label">
              {{ t('medical.complaints') }}
            </legend>
            <div class="flex min-w-0 flex-wrap items-center gap-3">
              <label
                v-for="symptom in symptoms"
                :key="symptom"
                class="inline-flex min-h-10 max-w-full items-center gap-2 rounded-full border border-stone-200 px-3 py-2 text-sm"
              >
                <input
                  v-model="form.answers.symptoms"
                  type="checkbox"
                  :value="symptom"
                  @change="clearClientError('complaints')"
                >
                {{ t('medical.' + symptom) }}
              </label>
            </div>
          </fieldset>
          <PainScale v-model="form.answers.pain_vas" />
          <div
            v-for="field in fields"
            :key="field"
            :data-medical-field="field"
            class="portal-field min-w-0"
          >
            <template v-if="field === 'operations' || field === 'injuries'">
              <fieldset class="portal-stack min-w-0">
                <legend class="portal-label">
                  {{ t('medical.' + field) }}
                </legend>
                <div class="portal-action-row">
                  <label
                    v-for="answer in [true, false]"
                    :key="String(answer)"
                    class="portal-copy"
                  >
                    <input
                      v-model="form.answers[field === 'operations' ? 'had_operations' : 'had_injuries']"
                      type="radio"
                      :name="field"
                      :value="answer"
                      required
                      :aria-invalid="Boolean(clientErrors[field === 'operations' ? 'had_operations' : 'had_injuries'])"
                      :aria-describedby="clientErrors[field === 'operations' ? 'had_operations' : 'had_injuries'] ? `medical-${field}-answer-error` : undefined"
                      @change="clearClientError(field, field === 'operations' ? 'had_operations' : 'had_injuries', field === 'operations' ? 'operation_year' : 'injury_year')"
                    >
                    {{ t(answer ? 'medical.yes' : 'medical.no') }}
                  </label>
                </div>
                <p
                  v-if="clientErrors[field === 'operations' ? 'had_operations' : 'had_injuries']"
                  :id="`medical-${field}-answer-error`"
                  class="portal-error"
                  role="alert"
                >
                  {{ clientErrors[field === 'operations' ? 'had_operations' : 'had_injuries'] }}
                </p>
                <label
                  v-if="form.answers[field === 'operations' ? 'had_operations' : 'had_injuries']"
                  :for="field === 'operations' ? 'medical-operation-year' : 'medical-injury-year'"
                  class="portal-field"
                >
                  <span class="portal-label">{{ t(field === 'operations' ? 'medical.operationYear' : 'medical.injuryYear') }}</span>
                  <input
                    :id="field === 'operations' ? 'medical-operation-year' : 'medical-injury-year'"
                    v-model.number="form.answers[field === 'operations' ? 'operation_year' : 'injury_year']"
                    type="number"
                    min="1900"
                    :max="currentYear"
                    required
                    :aria-invalid="Boolean(clientErrors[field === 'operations' ? 'operation_year' : 'injury_year'])"
                    :aria-describedby="clientErrors[field === 'operations' ? 'operation_year' : 'injury_year'] ? `medical-${field}-year-error` : undefined"
                    class="portal-input"
                    @input="clearClientError(field === 'operations' ? 'operation_year' : 'injury_year')"
                  >
                  <span
                    v-if="clientErrors[field === 'operations' ? 'operation_year' : 'injury_year']"
                    :id="`medical-${field}-year-error`"
                    class="portal-error"
                    role="alert"
                  >
                    {{ clientErrors[field === 'operations' ? 'operation_year' : 'injury_year'] }}
                  </span>
                </label>
                <label
                  v-if="form.answers[field === 'operations' ? 'had_operations' : 'had_injuries']"
                  :for="'medical-' + field"
                  class="portal-field"
                >
                  <span class="portal-label">{{ t(field === 'operations' ? 'medical.operationDetails' : 'medical.injuryDetails') }}</span>
                  <textarea
                    :id="'medical-' + field"
                    v-model="form[field]"
                    rows="3"
                    maxlength="10000"
                    required
                    :aria-invalid="Boolean(clientErrors[field])"
                    :aria-describedby="clientErrors[field] ? `medical-${field}-error` : undefined"
                    class="portal-input"
                    @input="clearClientError(field)"
                  />
                  <span
                    v-if="clientErrors[field]"
                    :id="`medical-${field}-error`"
                    class="portal-error"
                    role="alert"
                  >
                    {{ clientErrors[field] }}
                  </span>
                </label>
              </fieldset>
            </template>
            <template v-else>
              <label
                :for="'medical-' + field"
                class="portal-label"
              >{{ t(field === 'complaints' ? 'medical.complaintsDetails' : 'medical.' + field) }}</label>
              <label
                v-if="field === 'chronic_conditions'"
                class="portal-copy"
              >
                <input
                  v-model="form.answers.no_chronic_conditions"
                  type="checkbox"
                >
                {{ t('medical.noChronic') }}
              </label>
              <textarea
                v-if="field !== 'chronic_conditions' || !form.answers.no_chronic_conditions"
                :id="'medical-' + field"
                v-model="form[field]"
                rows="3"
                maxlength="10000"
                :required="field === 'goals'"
                :placeholder="field === 'goals' ? t('medical.goalsPlaceholder') : undefined"
                :aria-invalid="Boolean(clientErrors[field])"
                :aria-describedby="clientErrors[field] ? `medical-${field}-error` : undefined"
                class="portal-input"
                @input="clearClientError(field)"
              />
              <p
                v-if="clientErrors[field]"
                :id="`medical-${field}-error`"
                class="portal-error"
                role="alert"
              >
                {{ clientErrors[field] }}
              </p>
            </template>
          </div>
          <p
            v-for="(error, key) in form.errors"
            :key="key"
            class="portal-error"
            role="alert"
          >
            {{ error }}
          </p>
          <p
            v-if="form.processing"
            role="status"
            class="portal-copy"
          >
            {{ t('medical.saving') }}
          </p>
          <p
            v-if="requestError"
            class="portal-error"
            role="alert"
          >
            {{ requestError }}
          </p>
          <button
            type="submit"
            :disabled="form.processing || uploading"
            :aria-busy="form.processing"
            class="portal-button portal-button--primary"
          >
            {{ t(form.processing ? 'medical.saving' : 'medical.confirm') }}
          </button>
        </form>
        <template v-else>
          <PainScale
            :model-value="profile.answers.pain_vas ?? null"
            readonly
          />
          <p class="portal-copy">
            {{ profile.answers.symptoms?.map((symptom) => t('medical.' + symptom)).join(', ') }}
          </p>
          <dl class="portal-stack">
            <div
              v-for="field in fields"
              :key="field"
            >
              <dt class="portal-label">
                {{ t('medical.' + field) }}
              </dt>
              <dd class="portal-copy whitespace-pre-wrap break-words">
                {{ profile.fields[field] || t('medical.notSpecified') }}
              </dd>
            </div>
          </dl>
          <button
            type="button"
            class="portal-button portal-button--primary"
            @click="editing = true"
          >
            {{ t('medical.edit') }}
          </button>
        </template>
      </template>
      <MedicalAttachmentsUploader
        v-if="!profile.readFailed"
        :url="urls.upload"
        :materials="materials"
        @uploading="uploading = $event"
      />
      <Link
        :href="returnUrl"
        :aria-disabled="uploading"
        class="portal-button portal-button--secondary"
        @click="uploading && $event.preventDefault()"
      >
        {{ t(returnLabelKey) }}
      </Link>
    </section>
  </AppShell>
</template>
