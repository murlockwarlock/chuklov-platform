<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(defineProps<{ id: string; modelValue: string; suggestions: string[]; required?: boolean; emptyText: string; filterSuggestions?: boolean }>(), {
    filterSuggestions: true,
});
const emit = defineEmits<{
    'update:modelValue': [value: string];
    focus: [];
}>();
const root = ref<HTMLElement | null>(null);
const input = ref<HTMLInputElement | null>(null);
const open = ref(false);
const active = ref(-1);
const optionsId = computed(() => props.id + '-options');
const matches = computed(() => {
    const query = props.modelValue.trim().toLocaleLowerCase();

    return props.suggestions.filter((value) => props.filterSuggestions === false || value.toLocaleLowerCase().includes(query)).slice(0, 10);
});

function select(value: string): void {
    emit('update:modelValue', value);
    input.value?.focus();
    open.value = false;
    active.value = -1;
}

function type(event: Event): void {
    emit('update:modelValue', (event.target as HTMLInputElement).value);
    open.value = true;
    active.value = -1;
}

function focused(): void {
    open.value = true;
    emit('focus');
}

function keydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' || event.key === 'Tab') {
        open.value = false;
        active.value = -1;
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        open.value = true;
        if (matches.value.length) {
            active.value = active.value < 0
                ? (event.key === 'ArrowDown' ? 0 : matches.value.length - 1)
                : (active.value + (event.key === 'ArrowDown' ? 1 : -1) + matches.value.length) % matches.value.length;
        }
    } else if (event.key === 'Enter' && open.value && active.value >= 0) {
        const value = matches.value[active.value];
        if (value !== undefined) {
            event.preventDefault();
            select(value);
        }
    }
}

function outside(event: PointerEvent): void {
    if (event.target instanceof Node && !root.value?.contains(event.target)) {
        open.value = false;
        active.value = -1;
    }
}

function focusout(event: FocusEvent): void {
    if (!(event.relatedTarget instanceof Node) || !root.value?.contains(event.relatedTarget)) {
        open.value = false;
        active.value = -1;
    }
}

onMounted(() => document.addEventListener('pointerdown', outside));
onBeforeUnmount(() => document.removeEventListener('pointerdown', outside));
</script>

<template>
  <div
    ref="root"
    class="portal-autocomplete"
    @focusout="focusout"
  >
    <input
      :id="id"
      ref="input"
      :value="modelValue"
      :required="required"
      type="text"
      role="combobox"
      aria-autocomplete="list"
      :aria-expanded="open"
      :aria-controls="optionsId"
      :aria-activedescendant="open && active >= 0 ? optionsId + '-' + active : undefined"
      autocomplete="off"
      maxlength="100"
      class="portal-input"
      @focus="focused"
      @click="open = true"
      @input="type"
      @keydown="keydown"
    >
    <ul
      v-if="open"
      :id="optionsId"
      role="listbox"
      class="portal-autocomplete__options"
    >
      <li
        v-for="(value, index) in matches"
        :key="value"
      >
        <button
          :id="optionsId + '-' + index"
          type="button"
          role="option"
          :aria-selected="active === index"
          tabindex="-1"
          class="portal-autocomplete__option"
          @click="select(value)"
        >
          {{ value }}
        </button>
      </li>
      <li
        v-if="!matches.length"
        class="portal-copy portal-autocomplete__empty"
      >
        {{ emptyText }}
      </li>
    </ul>
  </div>
</template>

<style scoped>
.portal-autocomplete {
    position: relative;
    min-width: 0;
}

.portal-autocomplete__options {
    position: absolute;
    z-index: 30;
    inset-inline: 0;
    top: calc(100% + 0.25rem);
    max-height: 16rem;
    overflow-y: auto;
    border: 1px solid var(--portal-color-border);
    border-radius: var(--portal-radius-md);
    background: var(--portal-color-surface);
    padding: 0.25rem;
    box-shadow: 0 0.25rem 0.75rem rgb(0 0 0 / 8%);
}

.portal-autocomplete__option {
    display: block;
    width: 100%;
    min-height: 2.75rem;
    padding: 0.5rem 0.75rem;
    text-align: start;
    overflow-wrap: anywhere;
}

.portal-autocomplete__option:hover,
.portal-autocomplete__option[aria-selected="true"] {
    background: var(--portal-color-surface-muted);
}

.portal-autocomplete__empty {
    padding: 0.5rem 0.75rem;
}
</style>
