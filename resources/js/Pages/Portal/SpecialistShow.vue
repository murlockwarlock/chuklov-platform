<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import PortalPageHeader from '../../Components/Portal/PortalPageHeader.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Contact = {
    type: string;
    label: string;
    value: string;
    url: string;
};

type Service = {
    id: number;
    name: string;
    summary: string | null;
    imageUrl: string | null;
    durationMinutes: number | null;
    priceMajor: string | null;
    priceCurrency: string | null;
    bookingUrl: string;
};

type Specialist = {
    id: number;
    displayName: string;
    imageUrl: string | null;
    specialization: string | null;
    experienceLabel: string | null;
    qualification: string | null;
    bio: string | null;
    country: string | null;
    city: string | null;
    contacts: Contact[];
    services: Service[];
    bookingUrl: string;
};

const props = defineProps<{
    portal: PortalShell;
    specialist: Specialist;
    context: { serviceId: number; serviceName: string } | null;
    urls: { back: string; services: string };
}>();

const { t } = usePortalLocale();

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
  <AppShell
    :title="props.specialist.displayName"
    :portal="props.portal"
    active="bookings"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <PortalPageHeader
        :title="props.specialist.displayName"
        :back-href="props.urls.back"
        :back-label="t('specialist.backToBooking')"
      />

      <article class="portal-specialist-profile portal-panel">
        <div class="portal-specialist-profile__hero">
          <div class="portal-specialist-profile__avatar">
            <img
              v-if="props.specialist.imageUrl"
              :src="props.specialist.imageUrl"
              :alt="props.specialist.displayName"
              class="portal-specialist-profile__image"
            >
            <span
              v-else
              aria-hidden="true"
              class="portal-specialist-profile__initials"
            >
              {{ initials(props.specialist.displayName) }}
            </span>
          </div>
          <div class="portal-stack portal-stack--tight min-w-0">
            <p class="portal-kicker">
              {{ t('specialist.profileTitle') }}
            </p>
            <h1 class="portal-heading portal-heading--section">
              {{ props.specialist.displayName }}
            </h1>
            <p
              v-if="props.specialist.specialization"
              class="portal-specialist-profile__specialization"
            >
              {{ props.specialist.specialization }}
            </p>
            <p
              v-if="props.specialist.experienceLabel || props.specialist.city || props.specialist.country"
              class="portal-specialist-profile__meta"
            >
              <span v-if="props.specialist.experienceLabel">{{ props.specialist.experienceLabel }}</span>
              <span v-if="props.specialist.experienceLabel && (props.specialist.city || props.specialist.country)"> · </span>
              <span v-if="props.specialist.city || props.specialist.country">
                {{ [props.specialist.city, props.specialist.country].filter(Boolean).join(', ') }}
              </span>
            </p>
          </div>
        </div>

        <div
          v-if="props.context"
          class="portal-notice portal-notice--warning"
        >
          {{ t('specialist.context', { service: props.context.serviceName }) }}
        </div>

        <section
          v-if="props.specialist.qualification"
          class="portal-specialist-profile__section"
        >
          <h2 class="portal-heading portal-heading--section">
            {{ t('specialist.qualification') }}
          </h2>
          <p class="portal-specialist-profile__text">
            {{ props.specialist.qualification }}
          </p>
        </section>

        <section
          v-if="props.specialist.bio"
          class="portal-specialist-profile__section"
        >
          <h2 class="portal-heading portal-heading--section">
            {{ t('specialist.about') }}
          </h2>
          <p class="portal-specialist-profile__text">
            {{ props.specialist.bio }}
          </p>
        </section>

        <section
          v-if="props.specialist.contacts.length"
          class="portal-specialist-profile__section"
        >
          <h2 class="portal-heading portal-heading--section">
            {{ t('specialist.contacts') }}
          </h2>
          <div class="portal-specialist-profile__contacts">
            <a
              v-for="contact in props.specialist.contacts"
              :key="contact.type"
              :href="contact.url"
              class="portal-specialist-profile__contact portal-link"
              target="_blank"
              rel="noreferrer"
            >
              <span>{{ contact.label }}</span>
              <strong>{{ contact.value }}</strong>
            </a>
          </div>
        </section>

        <section
          v-if="props.specialist.services.length"
          class="portal-specialist-profile__section"
        >
          <h2 class="portal-heading portal-heading--section">
            {{ t('specialist.services') }}
          </h2>
          <div class="portal-specialist-profile__services">
            <article
              v-for="service in props.specialist.services"
              :key="service.id"
              class="portal-specialist-profile__service"
            >
              <div class="min-w-0 portal-stack portal-stack--tight">
                <h3 class="portal-heading portal-heading--card">
                  {{ service.name }}
                </h3>
                <p
                  v-if="service.summary"
                  class="portal-card__summary"
                >
                  {{ service.summary }}
                </p>
              </div>
              <Link
                :href="service.bookingUrl"
                class="portal-button portal-button--secondary"
              >
                {{ t('specialist.book') }}
              </Link>
            </article>
          </div>
        </section>

        <div class="portal-specialist-profile__actions">
          <Link
            :href="props.specialist.bookingUrl"
            class="portal-button portal-button--primary"
          >
            {{ t('specialist.book') }}
          </Link>
          <Link
            :href="props.urls.services"
            class="portal-link"
          >
            {{ t('specialist.services') }}
          </Link>
        </div>
      </article>
    </section>
  </AppShell>
</template>
