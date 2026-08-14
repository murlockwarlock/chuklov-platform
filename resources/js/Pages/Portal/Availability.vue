<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PortalDateTime from '../../Components/PortalDateTime.vue';

type AvailabilitySlot = {
    startsAt: string;
    endsAt: string;
    displayTimezone: string;
    format: 'office' | 'home' | 'online';
};

type Availability = {
    specialistId: number;
    serviceId: number;
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

const formatLabels: Record<AvailabilityQuery['format'], string> = {
    office: 'В клинике',
    home: 'Выезд на дом',
    online: 'Онлайн',
};

function formatDate(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Intl.DateTimeFormat('ru-RU', {
        day: 'numeric',
        month: 'long',
    }).format(new Date(year, month - 1, day));
}
</script>

<template>
  <Head title="Свободное время" />
  <main class="portal-page">
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-masthead">
        <div class="portal-masthead__copy portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            {{ formatLabels[props.query.format] }}
          </p>
          <h1 class="portal-heading portal-heading--page">
            Свободное время
          </h1>
          <p class="portal-lede">
            {{ formatDate(props.query.dateFrom) }} — {{ formatDate(props.query.dateTo) }}
          </p>
        </div>
      </header>

      <p
        v-if="props.availability.slots.length === 0"
        class="portal-notice"
        role="status"
      >
        Для выбранных параметров свободного времени пока нет.
      </p>

      <div
        v-else
        class="portal-grid portal-grid--cards"
        aria-label="Свободное время для записи"
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
            До
            <PortalDateTime
              :value="slot.endsAt"
              :time-zone="props.availability.displayTimezone"
            />
          </p>
        </article>
      </div>

      <Link
        href="/"
        class="portal-button portal-button--secondary self-start"
      >
        В личный кабинет
      </Link>
    </section>
  </main>
</template>
