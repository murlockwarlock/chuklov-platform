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
        :aria-invalid="errors?.[field.key] ? 'true' : 'false'"
        :aria-describedby="errors?.[field.key] ? idPrefix + '-' + field.key + '-error' : undefined"
        @input="update(field.key, ($event.target as HTMLInputElement).value)"
      >
      <p
        v-if="errors?.[field.key]"
        :id="idPrefix + '-' + field.key + '-error'"
        class="portal-error"
        role="alert"
      >
        {{ errors[field.key] }}
      </p>
    </div>
    <fieldset class="portal-field">
      <legend class="portal-label">
        {{ t('profile.gender') }}
      </legend>
      <div
        class="portal-segmented portal-profile-gender"
        :aria-invalid="errors?.gender ? 'true' : 'false'"
        :aria-describedby="errors?.gender ? idPrefix + '-gender-error' : undefined"
      >
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
            :aria-invalid="errors?.gender ? 'true' : 'false'"
            :aria-describedby="errors?.gender ? idPrefix + '-gender-error' : undefined"
            class="sr-only"
            @change="update('gender', gender)"
          >
          {{ t(gender === 'MALE' ? 'profile.male' : 'profile.female') }}
        </label>
      </div>
      <p
        v-if="errors?.gender"
        :id="idPrefix + '-gender-error'"
        class="portal-error"
        role="alert"
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
        :aria-invalid="errors?.birth_date ? 'true' : 'false'"
        :aria-describedby="errors?.birth_date ? idPrefix + '-birth-date-error' : undefined"
        @input="update('birth_date', ($event.target as HTMLInputElement).value)"
      >
      <p
        v-if="errors?.birth_date"
        :id="idPrefix + '-birth-date-error'"
        class="portal-error"
        role="alert"
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
        :aria-invalid="errors?.relationship ? 'true' : 'false'"
        :aria-describedby="errors?.relationship ? idPrefix + '-relationship-error' : undefined"
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
        :id="idPrefix + '-relationship-error'"
        class="portal-error"
        role="alert"
      >
        {{ errors.relationship }}
      </p>
    </div>
  </div>
</template>
