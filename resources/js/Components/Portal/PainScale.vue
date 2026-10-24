<script setup lang="ts">
import { computed } from 'vue';
import { usePortalLocale } from '../../composables/usePortalLocale';

const props = defineProps<{ modelValue: number | null; readonly?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();
const { t } = usePortalLocale();
const color = computed(() => props.modelValue === null ? '#737373' : `hsl(${120 - props.modelValue * 12} 65% 35%)`);
const mouth = computed(() => {
    const value = props.modelValue;
    if (value === null) return 'M8 15h8';
    return `M8 15 Q12 ${19 - value * 0.8} 16 15`;
});
function change(event: Event): void {
    emit('update:modelValue', Number((event.target as HTMLInputElement).value));
}
</script>

<template>
  <div class="portal-stack">
    <label
      for="medical-pain"
      class="portal-label"
    >{{ t('medical.pain') }}</label>
    <div
      class="portal-action-row"
      :style="{ color }"
      aria-live="polite"
    >
      <svg
        width="40"
        height="40"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        aria-hidden="true"
      >
        <circle
          cx="12"
          cy="12"
          r="10"
        />
        <circle
          cx="8"
          cy="9"
          r="0.7"
        />
        <circle
          cx="16"
          cy="9"
          r="0.7"
        />
        <path :d="mouth" />
      </svg>
      <strong>{{ modelValue === null ? t('medical.notSpecified') : modelValue + ' / 10' }}</strong>
    </div>
    <input
      v-if="!readonly"
      id="medical-pain"
      type="range"
      min="0"
      max="10"
      step="1"
      :value="modelValue ?? 0"
      :aria-valuetext="modelValue === null ? t('medical.notSpecified') : String(modelValue)"
      @input="change"
      @pointerup="change"
      @keydown.home="emit('update:modelValue', 0)"
      @keydown.end="emit('update:modelValue', 10)"
    >
    <div class="portal-section-heading">
      <span class="portal-copy portal-copy--small">0 — {{ t('medical.noPain') }}</span>
      <span class="portal-copy portal-copy--small">10 — {{ t('medical.maxPain') }}</span>
    </div>
    <button
      v-if="modelValue !== null && !readonly"
      type="button"
      class="portal-button portal-button--ghost"
      @click="emit('update:modelValue', null)"
    >
      {{ t('medical.notSpecified') }}
    </button>
  </div>
</template>
