<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { ref } from 'vue';
import PatientIdentityFields from './PatientIdentityFields.vue';
import PortalIcon from './PortalIcon.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import { emptyPatientIdentity, type PatientSummary } from '../../types/patients';

type BookingState = {
    serviceId: number | null;
    specialistId: number | null;
    format: string;
    dateFrom: string;
    dateTo: string;
    displayTimezone: string;
    workingLocationId: number | null;
    locationArea: string | null;
    selectedStartAt: string | null;
    bookingStep: 'time' | 'confirmation';
};

const props = defineProps<{ modelValue: number | null; patients: PatientSummary[]; hasMore: boolean; createUrl: string; bookingState: BookingState }>();
const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();
const { t } = usePortalLocale();
const dialog = ref<HTMLDialogElement | null>(null);
const form = useForm({ ...emptyPatientIdentity(), creation_key: crypto.randomUUID(), return_to: 'booking',
    service_id: props.bookingState.serviceId, specialist_id: props.bookingState.specialistId, format: props.bookingState.format,
    date_from: props.bookingState.dateFrom, date_to: props.bookingState.dateTo, display_timezone: props.bookingState.displayTimezone,
    working_location_id: props.bookingState.workingLocationId, location_area: props.bookingState.locationArea,
    selected_start_at: props.bookingState.selectedStartAt, booking_step: props.bookingState.bookingStep });

watch(() => props.bookingState, (bookingState) => {
    form.service_id = bookingState.serviceId;
    form.specialist_id = bookingState.specialistId;
    form.format = bookingState.format;
    form.date_from = bookingState.dateFrom;
    form.date_to = bookingState.dateTo;
    form.display_timezone = bookingState.displayTimezone;
    form.working_location_id = bookingState.workingLocationId;
    form.location_area = bookingState.locationArea;
    form.selected_start_at = bookingState.selectedStartAt;
    form.booking_step = bookingState.bookingStep;
}, { deep: true });
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
    <div class="portal-booking-patient-selector__actions">
      <button
        type="button"
        class="portal-button portal-button--secondary portal-booking-patient-selector__add"
        @click="dialog?.showModal()"
      >
        <PortalIcon name="plus" />
        {{ t('family.add') }}
      </button>
    </div>
    <dialog
      ref="dialog"
      class="portal-dialog"
    >
      <form
        class="portal-stack"
        novalidate
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
