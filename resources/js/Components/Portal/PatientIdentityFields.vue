<script setup lang="ts">
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PatientIdentity } from '../../types/patients';

const props = defineProps<{ modelValue: PatientIdentity; idPrefix: string; errors?: Partial<Record<keyof PatientIdentity, string>> }>();
const emit = defineEmits<{ 'update:modelValue': [value: PatientIdentity] }>();
const { t } = usePortalLocale();
const names = [{ key: 'last_name', label: 'profile.lastName', autocomplete: 'family-name' },
    { key: 'first_name', label: 'profile.firstName', autocomplete: 'given-name' },
    { key: 'middle_name', label: 'profile.middleName', autocomplete: 'additional-name' }] as const;

function update(field: keyof PatientIdentity, value: string): void {
    emit('update:modelValue', { ...props.modelValue, [field]: value });
}
</script>

<template>
  <div class="portal-grid portal-grid--two">
    <div
      v-for="field in names"
      :key="field.key"
      class="portal-field"
    >
      <label
        :for="idPrefix + '-' + field.key"
        class="portal-label"
      >{{ t(field.label) }}</label>
      <input
        :id="idPrefix + '-' + field.key"
        :value="modelValue[field.key]"
        :autocomplete="field.autocomplete"
        :required="field.key !== 'middle_name'"
        maxlength="100"
        class="portal-input"
        @input="update(field.key, ($event.target as HTMLInputElement).value)"
      >
      <p
        v-if="errors?.[field.key]"
        class="portal-error"
      >
        {{ errors[field.key] }}
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
          :class="{ 'portal-segmented__button--active': modelValue.gender === gender }"
        >
          <input
            :checked="modelValue.gender === gender"
            :name="idPrefix + '-gender'"
            type="radio"
            :value="gender"
            required
            class="sr-only"
            @change="update('gender', gender)"
          >
          {{ t(gender === 'MALE' ? 'profile.male' : 'profile.female') }}
        </label>
      </div>
      <p
        v-if="errors?.gender"
        class="portal-error"
      >
        {{ errors.gender }}
      </p>
    </fieldset>
    <div class="portal-field">
      <label
        :for="idPrefix + '-birth-date'"
        class="portal-label"
      >{{ t('profile.birthDate') }}</label>
      <input
        :id="idPrefix + '-birth-date'"
        :value="modelValue.birth_date"
        type="date"
        required
        class="portal-input"
        @input="update('birth_date', ($event.target as HTMLInputElement).value)"
      >
      <p
        v-if="errors?.birth_date"
        class="portal-error"
      >
        {{ errors.birth_date }}
      </p>
    </div>
    <div class="portal-field">
      <label
        :for="idPrefix + '-relationship'"
        class="portal-label"
      >{{ t('family.relationship') }}</label>
      <select
        :id="idPrefix + '-relationship'"
        :value="modelValue.relationship"
        required
        class="portal-input"
        @change="update('relationship', ($event.target as HTMLSelectElement).value)"
      >
        <option
          value=""
          disabled
        >
          {{ t('family.chooseRelationship') }}
        </option>
        <option value="SPOUSE">
          {{ t('family.spouse') }}
        </option>
        <option value="CHILD">
          {{ t('family.child') }}
        </option>
        <option value="PARENT">
          {{ t('family.parent') }}
        </option>
      </select>
      <p
        v-if="errors?.relationship"
        class="portal-error"
      >
        {{ errors.relationship }}
      </p>
    </div>
  </div>
</template>
