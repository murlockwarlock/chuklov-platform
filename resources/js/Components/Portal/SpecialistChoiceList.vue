<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

type SpecialistChoice = {
    id: number;
    displayName: string;
    imageUrl: string | null;
    specialization: string | null;
    experienceLabel: string | null;
    qualification: string | null;
    city: string | null;
    detailsUrl: string;
};

const props = defineProps<{
    headingId: string;
    heading: string;
    choices: SpecialistChoice[];
    selectedId: number | null;
    continueLabel: string;
    detailsLabel: string;
    changeLabel: string;
    contextLabel: string;
    contextValue?: string;
    contextTestId?: string;
    emptyMessage?: string;
}>();

const emit = defineEmits<{
    select: [id: number];
    continue: [];
    change: [];
}>();

function initials(name: string): string {
    return name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.slice(0, 1).toUpperCase())
        .join('') || '?';
}

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
        type="button"
        class="portal-link portal-link--button"
        @click="emit('change')"
      >
        {{ props.changeLabel }}
      </button>
    </div>

    <div
      v-if="props.contextValue"
      class="portal-booking-choice__context"
    >
      <span class="portal-label">{{ props.contextLabel }}</span>
      <strong :data-testid="props.contextTestId">{{ props.contextValue }}</strong>
    </div>

    <div
      v-if="props.choices.length"
      class="portal-specialist-choice-options"
    >
      <article
        v-for="choice in props.choices"
        :key="choice.id"
        class="portal-specialist-choice"
        :class="{ 'portal-specialist-choice--selected': props.selectedId === choice.id }"
      >
        <button
          type="button"
          class="portal-specialist-choice__select"
          :aria-pressed="props.selectedId === choice.id"
          @click="emit('select', choice.id)"
        >
          <span class="portal-specialist-choice__avatar">
            <img
              v-if="choice.imageUrl"
              :src="choice.imageUrl"
              :alt="choice.displayName"
              class="portal-specialist-choice__image"
            >
            <span
              v-else
              aria-hidden="true"
              class="portal-specialist-choice__initials"
            >
              {{ initials(choice.displayName) }}
            </span>
          </span>
          <span class="portal-specialist-choice__copy">
            <strong>{{ choice.displayName }}</strong>
            <span v-if="choice.specialization">{{ choice.specialization }}</span>
            <small v-if="choice.experienceLabel || choice.city">
              {{ [choice.experienceLabel, choice.city].filter(Boolean).join(' · ') }}
            </small>
            <small v-if="choice.qualification">{{ choice.qualification }}</small>
          </span>
          <span
            class="portal-specialist-choice__indicator"
            aria-hidden="true"
          />
        </button>
        <Link
          :href="choice.detailsUrl"
          class="portal-link portal-specialist-choice__details"
        >
          {{ props.detailsLabel }}
        </Link>
      </article>
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
