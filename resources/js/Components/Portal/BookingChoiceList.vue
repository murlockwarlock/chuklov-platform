<script setup lang="ts">
type Choice = {
    id: number;
    title: string;
    description?: string | null;
    meta?: string | null;
    trailing?: string | null;
};

const props = defineProps<{
    headingId: string;
    heading: string;
    choices: Choice[];
    selectedId: number | null;
    continueLabel: string;
    emptyMessage?: string;
    changeLabel?: string;
}>();

const emit = defineEmits<{
    select: [id: number];
    continue: [];
    change: [];
}>();
</script>

<template>
  <section
    class="portal-booking-choice portal-stack"
    :aria-labelledby="props.headingId"
  >
    <div class="portal-page-heading">
      <h2
        :id="props.headingId"
        class="portal-heading portal-heading--section"
      >
        {{ props.heading }}
      </h2>
      <button
        v-if="props.changeLabel"
        type="button"
        class="portal-link portal-link--button"
        @click="emit('change')"
      >
        {{ props.changeLabel }}
      </button>
    </div>

    <div
      v-if="props.choices.length"
      class="portal-booking-options"
    >
      <button
        v-for="choice in props.choices"
        :key="choice.id"
        type="button"
        class="portal-booking-option"
        :class="{ 'portal-booking-option--selected': props.selectedId === choice.id }"
        :aria-pressed="props.selectedId === choice.id"
        @click="emit('select', choice.id)"
      >
        <span
          class="portal-booking-option__indicator"
          aria-hidden="true"
        />
        <span class="portal-booking-option__copy">
          <strong>{{ choice.title }}</strong>
          <span v-if="choice.description">{{ choice.description }}</span>
          <small v-if="choice.meta">{{ choice.meta }}</small>
        </span>
        <strong
          v-if="choice.trailing"
          class="portal-booking-option__price"
        >
          {{ choice.trailing }}
        </strong>
      </button>
    </div>
    <p
      v-else-if="props.emptyMessage"
      class="portal-notice"
      role="status"
    >
      {{ props.emptyMessage }}
    </p>

    <button
      type="button"
      class="portal-button portal-button--primary self-start"
      :disabled="props.selectedId === null"
      @click="emit('continue')"
    >
      {{ props.continueLabel }}
    </button>
  </section>
</template>
