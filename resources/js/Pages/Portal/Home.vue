<script setup lang="ts">
import { Link, useForm, usePoll } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import BookingCard from '../../Components/Portal/BookingCard.vue';
import PortalIcon from '../../Components/Portal/PortalIcon.vue';
import Stories from '../../Components/Portal/Stories.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Booking = {
    id: number;
    service: { name: string };
    specialist: { displayName: string };
    startsAt: string;
    endsAt: string;
    localDate: string;
    localTime: string;
    localEndsAt: string;
    timezone: string;
    formatLabel: string;
    statusLabel: string;
    canReschedule: boolean;
};

type HealthAction = { title?: string; titleKey?: string; summary?: string; summaryKey?: string; url: string; kind: string; actionKey: string; badgeKey: string; taskEntryUrl?: string; taskStatus?: string };

const props = defineProps<{
    portal: PortalShell;
    upcomingBooking: Booking | null;
    healthAction: HealthAction | null;
    stories: Array<{ key: string; title: string; videoUrl: string | null; previewUrl: string | null; subtitlesUrl: string | null }>;
}>();

const { locale, t } = usePortalLocale();
usePoll(15000, { only: ['upcomingBooking', 'healthAction'] });
const taskForm = useForm({ status: 'completed' });
function completeTask(event: Event): void {
    if (!props.healthAction?.taskEntryUrl) return;
    const checkbox = event.target as HTMLInputElement;
    taskForm.status = checkbox.checked ? 'completed' : 'not_completed';
    taskForm.post(props.healthAction.taskEntryUrl, {
        preserveScroll: true,
        onError: () => { checkbox.checked = props.healthAction?.taskStatus === 'completed'; },
        onCancel: () => { checkbox.checked = props.healthAction?.taskStatus === 'completed'; },
    });
}
</script>

<template>
  <AppShell
    :title="t('shell.home')"
    :portal="props.portal"
    active="home"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <Stories :stories="props.stories" />
      <header class="portal-stack portal-stack--tight">
        <h1 class="portal-heading portal-heading--section min-w-0 [overflow-wrap:anywhere]">
          {{ props.portal.confirmedFirstName
            ? t('home.greetingWithName', { name: props.portal.confirmedFirstName })
            : t('home.greeting') }}
        </h1>
      </header>

      <BookingCard
        v-if="props.upcomingBooking"
        :booking="props.upcomingBooking"
        :locale="locale"
        :details-url="props.portal.urls.bookings + '/' + props.upcomingBooking.id"
      />
      <section
        v-else
        class="portal-content-section portal-stack"
        data-testid="home-empty-schedule"
      >
        <div class="portal-section-heading">
          <PortalIcon name="calendar" />
          <h2 class="portal-heading portal-heading--section">
            {{ t('home.schedule') }}
          </h2>
        </div>
        <p class="portal-copy">
          {{ t('home.noUpcomingDescription') }}
        </p>
        <Link
          :href="props.portal.urls.services"
          class="portal-button portal-button--primary self-start"
          data-testid="home-booking-cta"
        >
          {{ t('home.chooseTime') }}
        </Link>
      </section>
      <div
        v-if="props.upcomingBooking"
        class="portal-action-row"
      >
        <Link
          :href="props.portal.urls.services"
          class="portal-button portal-button--primary self-start"
          data-testid="home-booking-cta"
        >
          {{ props.upcomingBooking ? t('booking.bookAgain') : t('home.book') }}
        </Link>
      </div>

      <section
        v-if="props.healthAction"
        class="portal-content-section portal-stack rounded-2xl bg-orange-50"
      >
        <div class="portal-section-heading">
          <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-orange-100 text-orange-700"><PortalIcon name="health" /></span>
          <strong class="portal-list__title">{{ props.healthAction.titleKey ? t(props.healthAction.titleKey) : props.healthAction.title }}</strong>
          <span class="portal-count">{{ t(props.healthAction.badgeKey) }}</span>
        </div>
        <p class="portal-copy">
          {{ props.healthAction.summaryKey ? t(props.healthAction.summaryKey) : props.healthAction.summary }}
        </p>
        <label
          v-if="props.healthAction.kind === 'focus' && props.healthAction.taskEntryUrl"
          class="portal-copy"
        >
          <input
            type="checkbox"
            :checked="props.healthAction.taskStatus === 'completed'"
            :disabled="taskForm.processing"
            @change="completeTask"
          >
          {{ t('home.completed') }}
        </label>
        <p
          v-for="(error, key) in taskForm.errors"
          :key="key"
          class="portal-error"
        >
          {{ error }}
        </p>
        <Link
          :href="props.healthAction.url"
          class="portal-button portal-button--secondary"
        >
          {{ t(props.healthAction.actionKey) }}
        </Link>
      </section>

      <Link
        :href="props.portal.urls.companion"
        class="portal-list__row portal-list__row--standalone"
      >
        <span>
          <strong class="portal-list__title">{{ t('home.ai') }}</strong>
          <span class="portal-list__summary">{{ t('home.aiDescription') }}</span>
        </span>
        <PortalIcon
          name="arrow"
          class="portal-list__chevron"
        />
      </Link>
    </section>
  </AppShell>
</template>
