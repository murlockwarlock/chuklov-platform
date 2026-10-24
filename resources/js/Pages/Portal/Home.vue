<script setup lang="ts">
import { Link, router, usePoll } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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
    format: 'office' | 'home' | 'online';
    statusLabel: string;
    paymentStatusLabel: string;
    location: string | null;
    locationArea: string | null;
    locationSnapshot: { name?: string; address?: string };
    meetingUrl: string | null;
    meetingPending: boolean;
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
const taskCompleted = ref(props.healthAction?.taskStatus === 'completed');
const taskRequestError = ref('');
const taskStatusMessage = ref('');
const taskSaving = ref(false);
const taskStatusRefreshing = ref(false);

watch(() => props.healthAction?.taskStatus, (status) => {
    if (!taskSaving.value && !taskStatusRefreshing.value) {
        taskCompleted.value = status === 'completed';
    }
});

function restoreTaskStatus(): void {
    taskCompleted.value = props.healthAction?.taskStatus === 'completed';
}

function refreshTaskStatus(expectedStatus?: 'completed' | 'not_completed'): void {
    taskStatusRefreshing.value = true;
    taskStatusMessage.value = t('home.taskCheckingStatus');
    let refreshFailed = false;

    router.reload({
        only: ['healthAction'],
        onSuccess: () => {
            restoreTaskStatus();

            if (expectedStatus !== undefined && props.healthAction?.taskStatus === expectedStatus) {
                taskRequestError.value = '';
                taskStatusMessage.value = t(expectedStatus === 'completed' ? 'home.taskSavedCompleted' : 'home.taskSavedReset');
            } else if (expectedStatus !== undefined) {
                if (!taskRequestError.value) {
                    taskRequestError.value = t('home.taskSaveFailed');
                }

                taskStatusMessage.value = t('home.taskStatusRefreshed');
            }
        },
        onError: () => {
            refreshFailed = true;
        },
        onHttpException: () => {
            refreshFailed = true;

            return false;
        },
        onNetworkError: () => {
            refreshFailed = true;

            return false;
        },
        onFinish: () => {
            taskStatusRefreshing.value = false;
            restoreTaskStatus();

            if (refreshFailed) {
                taskStatusMessage.value = t('home.taskRefreshFailed');
            } else if (expectedStatus === undefined) {
                taskStatusMessage.value = t('home.taskStatusRefreshed');
            }
        },
    });
}

function completeTask(event: Event): void {
    if (!props.healthAction?.taskEntryUrl || taskSaving.value || taskStatusRefreshing.value) {
        restoreTaskStatus();

        return;
    }

    const checkbox = event.target as HTMLInputElement;
    const requestedStatus = checkbox.checked ? 'completed' : 'not_completed';
    taskCompleted.value = checkbox.checked;
    taskRequestError.value = '';
    taskStatusMessage.value = '';

    router.post(props.healthAction.taskEntryUrl, { status: requestedStatus }, {
        preserveScroll: true,
        onStart: () => {
            taskSaving.value = true;
            taskStatusMessage.value = t('home.taskSaving');
        },
        onSuccess: () => {
            restoreTaskStatus();

            if (props.healthAction?.taskStatus !== requestedStatus) {
                taskRequestError.value = t('home.taskSaveFailed');
                refreshTaskStatus(requestedStatus);

                return;
            }

            taskStatusMessage.value = t(requestedStatus === 'completed' ? 'home.taskSavedCompleted' : 'home.taskSavedReset');
        },
        onError: (errors) => {
            restoreTaskStatus();
            taskStatusMessage.value = '';
            taskRequestError.value = Object.values(errors).find((message) => typeof message === 'string')
                ?? t('home.taskValidationFailed');
        },
        onHttpException: (response) => {
            restoreTaskStatus();
            taskStatusMessage.value = '';
            taskRequestError.value = response.status === 401 || response.status === 419
                ? t('home.taskSessionExpired')
                : response.status === 403
                    ? t('home.taskAccessDenied')
                    : response.status === 404
                        ? t('home.taskUnavailable')
                        : response.status === 409
                            ? t('home.taskConflict')
                            : response.status === 422
                                ? t('home.taskValidationFailed')
                                : t('home.taskSaveFailed');

            if (response.status === 404 || response.status === 409 || response.status >= 500) {
                refreshTaskStatus(requestedStatus);
            }

            return false;
        },
        onNetworkError: () => {
            restoreTaskStatus();
            taskStatusMessage.value = '';
            taskRequestError.value = t('home.taskNetworkError');
            refreshTaskStatus(requestedStatus);

            return false;
        },
        onCancel: () => {
            restoreTaskStatus();
            taskStatusMessage.value = '';
            taskRequestError.value = t('home.taskSaveFailed');
            refreshTaskStatus(requestedStatus);
        },
        onFinish: () => {
            taskSaving.value = false;
        },
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
          <PortalIcon name="arrow" />
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
        class="portal-content-section portal-stack rounded-2xl bg-orange-50 p-5 sm:p-6"
        data-testid="home-health-action"
      >
        <div class="portal-health-action__heading">
          <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-orange-100 text-orange-700"><PortalIcon name="health" /></span>
          <strong class="portal-list__title portal-health-action__title">{{ props.healthAction.titleKey ? t(props.healthAction.titleKey) : props.healthAction.title }}</strong>
          <span class="portal-count portal-health-action__badge">{{ t(props.healthAction.badgeKey) }}</span>
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
            :checked="taskCompleted"
            :disabled="taskSaving || taskStatusRefreshing"
            @change="completeTask"
          >
          {{ t('home.completed') }}
        </label>
        <p
          v-if="taskSaving || taskStatusRefreshing || taskStatusMessage"
          class="portal-notice"
          role="status"
          aria-live="polite"
        >
          {{ taskSaving ? t('home.taskSaving') : taskStatusMessage }}
        </p>
        <p
          v-if="taskRequestError"
          class="portal-error"
          role="alert"
          aria-live="assertive"
        >
          {{ taskRequestError }}
        </p>
        <Link
          :href="props.healthAction.url"
          class="portal-button portal-button--secondary"
        >
          {{ t(props.healthAction.actionKey) }}
        </Link>
      </section>
      <p
        v-if="!props.healthAction?.taskEntryUrl && taskStatusMessage"
        class="portal-notice"
        role="status"
        aria-live="polite"
      >
        {{ taskStatusMessage }}
      </p>
      <p
        v-if="!props.healthAction?.taskEntryUrl && taskRequestError"
        class="portal-error"
        role="alert"
        aria-live="assertive"
      >
        {{ taskRequestError }}
      </p>

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
