<script setup lang="ts">
import { computed } from 'vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PersonalData } from '../../types/personalData';
import { countryNames } from '../../utils/countryNames';
import { formatPhone, phoneExample } from '../../utils/profilePhone';
import TextAutocomplete from './TextAutocomplete.vue';
import CityAutocomplete from './CityAutocomplete.vue';

const props = defineProps<{ modelValue: PersonalData; idPrefix: string; citiesUrl: string; emailEditable: boolean; errors?: Partial<Record<string, string>> }>();
const emit = defineEmits<{ 'update:modelValue': [value: PersonalData] }>();
const { locale, t } = usePortalLocale();
const countries = computed(() => countryNames(locale.value));
const fields = [
    { key: 'last_name', label: 'profile.lastName', autocomplete: 'family-name' },
    { key: 'first_name', label: 'profile.firstName', autocomplete: 'given-name' },
    { key: 'middle_name', label: 'profile.middleName', autocomplete: 'additional-name' },
] as const;

function update(key: keyof PersonalData, value: string): void {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}

</script>

<template>
  <div class="portal-grid portal-grid--two">
    <div
      v-for="field in fields"
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
        type="text"
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
            type="radio"
            :name="idPrefix + '-gender'"
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
        :for="idPrefix + '-country'"
        class="portal-label"
      >{{ t('profile.country') }}</label>
      <TextAutocomplete
        :id="idPrefix + '-country'"
        :model-value="modelValue.country"
        :suggestions="countries"
        :empty-text="t('profile.countryNoMatch')"
        required
        @update:model-value="update('country', $event)"
      />
      <p
        v-if="errors?.country"
        class="portal-error"
      >
        {{ errors.country }}
      </p>
    </div>
    <div class="portal-field">
      <label
        :for="idPrefix + '-city'"
        class="portal-label"
      >{{ t('profile.city') }}</label>
      <CityAutocomplete
        :id="idPrefix + '-city'"
        :model-value="modelValue.city"
        :country="modelValue.country"
        :cities-url="citiesUrl"
        required
        @update:model-value="update('city', $event)"
      />
      <p
        v-if="errors?.city"
        class="portal-error"
      >
        {{ errors.city }}
      </p>
    </div>
    <div class="portal-field">
      <label
        :for="idPrefix + '-phone'"
        class="portal-label"
      >{{ t('profile.phone') }}</label>
      <input
        :id="idPrefix + '-phone'"
        :value="modelValue.phone"
        type="tel"
        required
        autocomplete="tel"
        maxlength="32"
        class="portal-input"
        :placeholder="phoneExample(modelValue.country)"
        @input="update('phone', ($event.target as HTMLInputElement).value)"
        @blur="update('phone', formatPhone(modelValue.phone, modelValue.country))"
      >
      <p
        v-if="errors?.phone"
        class="portal-error"
      >
        {{ errors.phone }}
      </p>
    </div>
    <div class="portal-field portal-field--wide">
      <label
        :for="idPrefix + '-email'"
        class="portal-label"
      >{{ t('profile.email') }}</label>
      <input
        :id="idPrefix + '-email'"
        :value="modelValue.email"
        type="email"
        autocomplete="email"
        maxlength="320"
        :disabled="!emailEditable"
        class="portal-input"
        @input="update('email', ($event.target as HTMLInputElement).value)"
      >
      <p class="portal-copy portal-copy--small">
        {{ t('profile.emailPurpose') }}
      </p>
      <p
        v-if="!emailEditable"
        class="portal-copy portal-copy--small"
      >
        {{ t('profile.emailManaged') }}
      </p>
      <p
        v-if="errors?.email"
        class="portal-error"
      >
        {{ errors.email }}
      </p>
    </div>
  </div>
</template>
