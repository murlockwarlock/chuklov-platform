<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
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
type Pain = {
    id?: number;
    lineage_key?: string;
    name: string;
    score: number | null;
};
const props = defineProps<{
    portal: PortalShell;
    profile: { available: boolean; readFailed: boolean; expectedSnapshot: string; fields: Record<string, string | null>; answers: Partial<Answers>; legacyFields: Record<string, string>; pains: Pain[] };
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
const pains = ref<Pain[]>(props.profile.pains.map((pain) => ({ ...pain })));
const medicalForm = ref<HTMLFormElement | null>(null);
const fields = ['complaints', 'goals', 'chronic_conditions', 'operations', 'injuries', 'medicines', 'supplements'] as const;
const symptoms = ['headache', 'neck', 'back', 'joints', 'swelling', 'digestion', 'weakness', 'insomnia', 'stress'];

function displayField(field: typeof fields[number]): string {
    if (field === 'chronic_conditions' && props.profile.answers.no_chronic_conditions) {
        return t('medical.noChronic');
    }

    return props.profile.fields[field] || t('medical.notSpecified');
}

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
    pains: pains.value.map((pain) => ({ ...pain })),
});
function addPain(): void {
    pains.value.push({ name: '', score: null });
    syncPains();
}

function removePain(index: number): void {
    pains.value.splice(index, 1);
    syncPains();
}

function syncPains(): void {
    form.pains = pains.value.map((pain) => ({ ...pain }));
}
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
          class="portal-stack medical-profile__form"
          novalidate
          @submit.prevent="save"
        >
          <section class="portal-panel portal-panel--compact portal-stack medical-profile__assessment">
            <fieldset
              class="portal-stack min-w-0"
              data-medical-field="complaints"
            >
              <legend class="portal-label">
                {{ t('medical.complaints') }}
              </legend>
              <div class="medical-profile__choice-grid medical-profile__choice-grid--symptoms">
                <label
                  v-for="symptom in symptoms"
                  :key="symptom"
                  class="medical-profile__choice"
                  :class="{ 'medical-profile__choice--selected': form.answers.symptoms.includes(symptom) }"
                >
                  <input
                    v-model="form.answers.symptoms"
                    type="checkbox"
                    :value="symptom"
                    class="medical-profile__choice-control"
                    @change="clearClientError('complaints')"
                  >
                  <span class="min-w-0">{{ t('medical.' + symptom) }}</span>
                </label>
              </div>
            </fieldset>
          </section>
          <section class="portal-panel portal-panel--compact portal-stack">
            <div class="flex items-center justify-between gap-3">
              <h2 class="portal-label">
                {{ t('medical.pains') }}
              </h2>
              <button
                type="button"
                class="portal-button portal-button--secondary"
                @click="addPain"
              >
                {{ t('medical.addPain') }}
              </button>
            </div>
            <p class="portal-copy">
              {{ t('medical.painsDescription') }}
            </p>
            <div
              v-if="pains.length"
              class="portal-stack"
            >
              <div
                v-for="(pain, index) in pains"
                :key="pain.lineage_key || index"
                class="portal-panel portal-panel--compact portal-stack"
              >
                <div class="flex items-start justify-between gap-3">
                  <label
                    :for="`medical-pain-${index}`"
                    class="portal-label"
                  >{{ t('medical.painName') }}</label>
                  <button
                    type="button"
                    class="portal-button portal-button--secondary"
                    @click="removePain(index)"
                  >
                    {{ t('medical.removePain') }}
                  </button>
                </div>
                <input
                  :id="`medical-pain-${index}`"
                  v-model="pain.name"
                  type="text"
                  maxlength="1000"
                  class="portal-input"
                  @input="syncPains"
                >
                <label
                  :for="`medical-pain-score-${index}`"
                  class="portal-label"
                >{{ t('medical.painScore') }}</label>
                <input
                  :id="`medical-pain-score-${index}`"
                  v-model.number="pain.score"
                  type="range"
                  min="0"
                  max="10"
                  step="1"
                  class="w-full"
                  @input="syncPains"
                >
                <span class="portal-copy">{{ pain.score === null ? t('medical.notSpecified') : `${pain.score} / 10` }}</span>
              </div>
            </div>
            <p
              v-else
              class="portal-copy"
            >
              {{ t('medical.noPains') }}
            </p>
          </section>
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
                <div class="medical-profile__choice-grid">
                  <label
                    v-for="answer in [true, false]"
                    :key="String(answer)"
                    class="medical-profile__choice"
                    :class="{ 'medical-profile__choice--selected': form.answers[field === 'operations' ? 'had_operations' : 'had_injuries'] === answer }"
                  >
                    <input
                      v-model="form.answers[field === 'operations' ? 'had_operations' : 'had_injuries']"
                      type="radio"
                      :name="field"
                      :value="answer"
                      required
                      :aria-invalid="Boolean(clientErrors[field === 'operations' ? 'had_operations' : 'had_injuries'])"
                      :aria-describedby="clientErrors[field === 'operations' ? 'had_operations' : 'had_injuries'] ? `medical-${field}-answer-error` : undefined"
                      class="medical-profile__choice-control"
                      @change="clearClientError(field, field === 'operations' ? 'had_operations' : 'had_injuries', field === 'operations' ? 'operation_year' : 'injury_year')"
                    >
                    <span>{{ t(answer ? 'medical.yes' : 'medical.no') }}</span>
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
                <div
                  v-if="form.answers[field === 'operations' ? 'had_operations' : 'had_injuries']"
                  class="portal-field"
                >
                  <label
                    :for="field === 'operations' ? 'medical-operation-year' : 'medical-injury-year'"
                    class="portal-label"
                  >
                    {{ t(field === 'operations' ? 'medical.operationYear' : 'medical.injuryYear') }}
                  </label>
                  <input
                    :id="field === 'operations' ? 'medical-operation-year' : 'medical-injury-year'"
                    v-model.number="form.answers[field === 'operations' ? 'operation_year' : 'injury_year']"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]{4}"
                    maxlength="4"
                    required
                    :aria-invalid="Boolean(clientErrors[field === 'operations' ? 'operation_year' : 'injury_year'])"
                    :aria-describedby="clientErrors[field === 'operations' ? 'operation_year' : 'injury_year'] ? `medical-${field}-year-error` : undefined"
                    class="portal-input"
                    :placeholder="t('medical.yearPlaceholder')"
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
                </div>
                <div
                  v-if="form.answers[field === 'operations' ? 'had_operations' : 'had_injuries']"
                  class="portal-field"
                >
                  <label
                    :for="'medical-' + field"
                    class="portal-label"
                  >
                    {{ t(field === 'operations' ? 'medical.operationDetails' : 'medical.injuryDetails') }}
                  </label>
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
                </div>
              </fieldset>
            </template>
            <template v-else>
              <label
                :for="'medical-' + field"
                class="portal-label"
              >{{ t(field === 'complaints' ? 'medical.complaintsDetails' : 'medical.' + field) }}</label>
              <label
                v-if="field === 'chronic_conditions'"
                class="portal-copy medical-profile__inline-choice"
              >
                <input
                  v-model="form.answers.no_chronic_conditions"
                  type="checkbox"
                  class="medical-profile__choice-control"
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
          <section class="portal-panel portal-panel--compact portal-stack medical-profile__assessment">
            <div
              v-if="profile.answers.symptoms?.length"
              class="medical-profile__symptoms"
            >
              <span
                v-for="symptom in profile.answers.symptoms"
                :key="symptom"
                class="medical-profile__symptom"
              >
                {{ t('medical.' + symptom) }}
              </span>
            </div>
          </section>
          <section class="portal-panel portal-panel--compact portal-stack">
            <h2 class="portal-label">
              {{ t('medical.pains') }}
            </h2>
            <div
              v-if="profile.pains.length"
              class="portal-stack"
            >
              <div
                v-for="pain in profile.pains"
                :key="pain.lineage_key || pain.id"
                class="flex items-center justify-between gap-3"
              >
                <span class="portal-copy break-words">{{ pain.name }}</span>
                <span class="portal-copy whitespace-nowrap">{{ pain.score === null ? t('medical.notSpecified') : `${pain.score} / 10` }}</span>
              </div>
            </div>
            <p
              v-else
              class="portal-copy"
            >
              {{ t('medical.noPains') }}
            </p>
          </section>
          <dl class="portal-stack">
            <div
              v-for="field in fields"
              :key="field"
            >
              <dt class="portal-label">
                {{ t('medical.' + field) }}
              </dt>
              <dd class="portal-copy whitespace-pre-wrap break-words">
                {{ displayField(field) }}
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
