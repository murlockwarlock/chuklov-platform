<script setup lang="ts">
import { computed, type CSSProperties } from 'vue';
import { usePortalLocale } from '../../composables/usePortalLocale';

const props = defineProps<{ modelValue: number | null; readonly?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();
const { t } = usePortalLocale();
const color = computed(() => props.modelValue === null ? '#737373' : `hsl(${120 - props.modelValue * 12} 65% 35%)`);
const rangeStyle = computed<CSSProperties>(() => ({ '--pain-progress': `${(props.modelValue ?? 0) * 10}%` }));
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
  <div class="portal-stack medical-pain-scale">
    <div class="medical-pain-scale__heading">
      <label
        v-if="!readonly"
        for="medical-pain"
        class="portal-label medical-pain-scale__label"
      >{{ t('medical.pain') }}</label>
      <span
        v-else
        class="portal-label medical-pain-scale__label"
      >{{ t('medical.pain') }}</span>
      <div
        class="medical-pain-scale__reading"
        :style="{ color }"
        aria-live="polite"
      >
        <svg
          class="medical-pain-scale__face"
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
        <strong class="medical-pain-scale__score">{{ modelValue === null ? t('medical.notSpecified') : modelValue + ' / 10' }}</strong>
      </div>
    </div>
    <input
      v-if="!readonly"
      id="medical-pain"
      type="range"
      min="0"
      max="10"
      step="1"
      :value="modelValue ?? 0"
      :style="rangeStyle"
      class="medical-pain-scale__range"
      :aria-valuetext="modelValue === null ? t('medical.notSpecified') : String(modelValue)"
      @input="change"
      @pointerup="change"
      @keydown.home="emit('update:modelValue', 0)"
      @keydown.end="emit('update:modelValue', 10)"
    >
    <div class="medical-pain-scale__bounds">
      <span class="medical-pain-scale__bound">0 — {{ t('medical.noPain') }}</span>
      <span class="medical-pain-scale__bound">10 — {{ t('medical.maxPain') }}</span>
    </div>
    <button
      v-if="modelValue !== null && !readonly"
      type="button"
      class="portal-button portal-button--ghost medical-pain-scale__reset"
      @click="emit('update:modelValue', null)"
    >
      {{ t('medical.notSpecified') }}
    </button>
  </div>
</template>
