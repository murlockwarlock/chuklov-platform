<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { ref } from 'vue';
import PatientIdentityFields from './PatientIdentityFields.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import { emptyPatientIdentity, type PatientSummary } from '../../types/patients';

const props = defineProps<{ modelValue: number | null; patients: PatientSummary[]; hasMore: boolean; createUrl: string; familyUrl: string; serviceId: number | null; specialistId: number | null; format: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();
const { t } = usePortalLocale();
const dialog = ref<HTMLDialogElement | null>(null);
const form = useForm({ ...emptyPatientIdentity(), creation_key: crypto.randomUUID(), return_to: 'booking',
    service_id: props.serviceId, specialist_id: props.specialistId, format: props.format });

watch(() => [props.serviceId, props.specialistId, props.format] as const, ([serviceId, specialistId, format]) => {
    form.service_id = serviceId;
    form.specialist_id = specialistId;
    form.format = format;
});
</script>

<template>
  <div class="portal-stack portal-stack--tight">
    <label
      for="booking-patient"
      class="portal-label"
    >{{ t('family.forWhom') }}</label>
    <select
      id="booking-patient"
      :value="props.modelValue ?? ''"
      class="portal-input"
      @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null)"
    >
      <option value="">
        {{ t('family.self') }}
      </option>
      <option
        v-for="patient in props.patients.filter((item) => item.relationship !== 'SELF')"
        :key="patient.id"
        :value="patient.id"
      >
        {{ patient.name }} · {{ patient.relationshipLabel }}
      </option>
    </select>
    <div class="portal-form-actions">
      <button
        type="button"
        class="portal-link"
        @click="dialog?.showModal()"
      >
        {{ t('family.add') }}
      </button>
      <Link
        :href="props.familyUrl"
        class="portal-link"
      >
        {{ t('family.title') }}
      </Link>
    </div>
    <dialog
      ref="dialog"
      class="portal-dialog"
    >
      <form
        class="portal-stack"
        @submit.prevent="form.post(props.createUrl)"
      >
        <h2 class="portal-heading portal-heading--section">
          {{ t('family.add') }}
        </h2>
        <PatientIdentityFields
          :model-value="form.data()"
          id-prefix="booking-patient-new"
          :errors="form.errors"
          @update:model-value="Object.assign(form, $event)"
        />
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
  </div>
</template>
