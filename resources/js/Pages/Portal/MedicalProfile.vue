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
}>();
const { t } = usePortalLocale();
const editing = ref(props.editing || !props.profile.available);
const uploading = ref(false);
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
function save(): void {
    form.post(props.urls.update, {
        preserveScroll: true,
        onSuccess: () => {
            form.expected_snapshot = props.profile.expectedSnapshot;
            form.defaults();
            editing.value = false;
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
          class="portal-stack"
          @submit.prevent="save"
        >
          <fieldset class="portal-stack min-w-0">
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
                >
                {{ t('medical.' + symptom) }}
              </label>
            </div>
          </fieldset>
          <PainScale v-model="form.answers.pain_vas" />
          <div
            v-for="field in fields"
            :key="field"
            class="portal-field"
          >
            <label
              :for="'medical-' + field"
              class="portal-label"
            >{{ t('medical.' + field) }}</label>
            <template v-if="field === 'operations' || field === 'injuries'">
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
                  >
                  {{ t(answer ? 'medical.yes' : 'medical.no') }}
                </label>
              </div>
              <label
                v-if="form.answers[field === 'operations' ? 'had_operations' : 'had_injuries']"
                class="portal-label"
              >
                {{ t('medical.year') }}
                <input
                  v-model.number="form.answers[field === 'operations' ? 'operation_year' : 'injury_year']"
                  type="number"
                  min="1900"
                  required
                  class="portal-input"
                >
              </label>
            </template>
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
              v-if="(field !== 'operations' || form.answers.had_operations) && (field !== 'injuries' || form.answers.had_injuries) && (field !== 'chronic_conditions' || !form.answers.no_chronic_conditions)"
              :id="'medical-' + field"
              v-model="form[field]"
              rows="3"
              maxlength="10000"
              :required="field === 'goals'"
              class="portal-input"
            />
          </div>
          <p
            v-for="(error, key) in form.errors"
            :key="key"
            class="portal-error"
            role="alert"
          >
            {{ error }}
          </p>
          <button
            type="submit"
            :disabled="form.processing || uploading"
            class="portal-button portal-button--primary"
          >
            {{ t('medical.confirm') }}
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
        {{ t('bookings.title') }}
      </Link>
    </section>
  </AppShell>
</template>
