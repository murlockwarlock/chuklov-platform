<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PortalDateTime from '../../Components/PortalDateTime.vue';

type AvailabilitySlot = {
    startsAt: string;
    endsAt: string;
    blockingEndsAt: string;
    scheduleTimezone: string;
    displayTimezone: string;
    format: 'office' | 'home' | 'online';
};

type Availability = {
    specialistId: number;
    serviceId: number;
    scheduleTimezone: string;
    displayTimezone: string;
    slots: AvailabilitySlot[];
};

type AvailabilityQuery = {
    specialistId: number;
    serviceId: number;
    dateFrom: string;
    dateTo: string;
    format: 'office' | 'home' | 'online';
};

const props = defineProps<{
    availability: Availability;
    query: AvailabilityQuery;
}>();
</script>

<template>
  <Head title="Availability" />
  <main class="portal-page">
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-masthead">
        <div class="portal-masthead__copy portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            {{ props.query.format }}
          </p>
          <h1 class="portal-heading portal-heading--page">
            Available times
          </h1>
          <p class="portal-lede">
            {{ props.query.dateFrom }} to {{ props.query.dateTo }} · shown in
            {{ props.availability.displayTimezone }}
          </p>
        </div>
      </header>

      <p
        v-if="props.availability.slots.length === 0"
        class="portal-notice"
        role="status"
      >
        No times are currently available for this selection.
      </p>

      <div
        v-else
        class="portal-grid portal-grid--cards"
        aria-label="Available appointment times"
      >
        <article
          v-for="slot in props.availability.slots"
          :key="slot.startsAt"
          class="portal-panel portal-stack portal-stack--tight"
        >
          <p class="portal-heading portal-heading--section">
            <PortalDateTime
              :value="slot.startsAt"
              :time-zone="props.availability.displayTimezone"
            />
          </p>
          <p class="portal-copy portal-copy--small">
            Ends
            <PortalDateTime
              :value="slot.endsAt"
              :time-zone="props.availability.displayTimezone"
            />
          </p>
          <p class="portal-copy portal-copy--small">
            Specialist schedule: {{ slot.scheduleTimezone }}
          </p>
        </article>
      </div>

      <Link
        href="/"
        class="portal-button portal-button--secondary self-start"
      >
        Back to portal
      </Link>
    </section>
  </main>
</template>
