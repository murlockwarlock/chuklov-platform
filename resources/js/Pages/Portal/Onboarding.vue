<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

type Stage = 'contacts' | 'profile' | 'service' | 'goals';

type Profile = {
    full_name: string | null;
    email: string | null;
    phone: string | null;
    language: string | null;
    timezone: string | null;
    lead_source: string | null;
    referral_code: string | null;
};

type Service = {
    id: number;
    name: string;
    summary: string;
};

type OnboardingForm = {
    full_name: string;
    email: string;
    phone: string;
    language: string;
    timezone: string;
    lead_source: string;
    referral_code: string;
    confirmed_fields: string[];
};

const props = defineProps<{
    flowVersion: string;
    currentStage: Stage;
    stages: Stage[];
    profile: Profile;
    knownFields: string[];
    missingFields: string[];
    blockedStages: Stage[];
    services: Service[];
}>();

const labels: Record<Stage, string> = {
    contacts: 'Contacts and attribution',
    profile: 'Profile',
    service: 'Service and format',
    goals: 'Goals and consents',
};

const form = useForm<OnboardingForm>({
    full_name: props.profile.full_name ?? '',
    email: props.profile.email ?? '',
    phone: props.profile.phone ?? '',
    language: props.profile.language ?? 'en',
    timezone: props.profile.timezone ?? 'UTC',
    lead_source: props.profile.lead_source ?? '',
    referral_code: props.profile.referral_code ?? '',
    confirmed_fields: [...props.knownFields],
});

const currentStageIndex = computed(() => props.stages.indexOf(props.currentStage));
const isBlocked = computed(() => props.blockedStages.includes(props.currentStage));

function fieldError(field: keyof OnboardingForm): string | undefined {
    return form.errors[field];
}

function generalError(field: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[field];
}

function submitStage(): void {
    form.transform((data) =>
        props.currentStage === 'contacts'
            ? data
            : { confirmed_fields: [] },
    ).post(`/portal/onboarding/${props.currentStage}`, {
        preserveScroll: true,
    });
}
</script>

<template>
  <Head title="Client onboarding" />
  <main class="portal-page">
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-masthead">
        <div class="portal-masthead__copy portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            Client portal
          </p>
          <h1 class="portal-heading portal-heading--page">
            Onboarding
          </h1>
          <p class="portal-copy portal-copy--small">
            Flow {{ props.flowVersion }}. Known information is shown for confirmation, and later steps stay limited to the current milestone.
          </p>
        </div>
        <Link
          href="/"
          class="portal-button portal-button--secondary"
        >
          Back to portal
        </Link>
      </header>

      <ol
        class="portal-stage-list"
        aria-label="Onboarding progress"
      >
        <li
          v-for="(stage, index) in props.stages"
          :key="stage"
          class="portal-stage"
          :class="{
            'portal-stage--complete': index < currentStageIndex,
            'portal-stage--current': index === currentStageIndex,
          }"
        >
          <p class="portal-stage__number">
            {{ index + 1 }}
          </p>
          <p class="portal-stage__label">
            {{ labels[stage] }}
          </p>
        </li>
      </ol>

      <section
        class="portal-panel portal-stack portal-stack--loose"
        aria-labelledby="stage-heading"
      >
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            Current step
          </p>
          <h2
            id="stage-heading"
            class="portal-heading portal-heading--section"
          >
            {{ labels[props.currentStage] }}
          </h2>
        </div>

        <form
          v-if="props.currentStage === 'contacts'"
          class="portal-grid portal-grid--form"
          @submit.prevent="submitStage"
        >
          <div class="portal-field portal-field--wide">
            <label
              for="full_name"
              class="portal-label"
            >Full name</label>
            <input
              id="full_name"
              v-model="form.full_name"
              required
              autocomplete="name"
              class="portal-input"
            >
            <p
              v-if="fieldError('full_name')"
              class="portal-error"
            >
              {{ fieldError('full_name') }}
            </p>
          </div>

          <div
            v-for="field in ['email', 'phone', 'language', 'timezone', 'lead_source', 'referral_code']"
            :key="field"
            class="portal-field"
          >
            <label
              :for="field"
              class="portal-label"
            >
              {{ field.replace('_', ' ') }}
              <span
                v-if="props.missingFields.includes(field)"
                class="portal-muted"
              >(optional)</span>
            </label>
            <input
              :id="field"
              v-model="form[field as keyof OnboardingForm]"
              :type="field === 'email' ? 'email' : 'text'"
              :autocomplete="field === 'email' ? 'email' : field === 'phone' ? 'tel' : undefined"
              class="portal-input"
            >
            <p
              v-if="fieldError(field as keyof OnboardingForm)"
              class="portal-error"
            >
              {{ fieldError(field as keyof OnboardingForm) }}
            </p>
            <label
              v-if="props.knownFields.includes(field)"
              class="portal-confirm"
            >
              <input
                v-model="form.confirmed_fields"
                type="checkbox"
                :value="field"
                class="portal-checkbox"
              >
              <span>Confirm this known value</span>
            </label>
          </div>

          <label
            v-if="props.knownFields.includes('full_name')"
            class="portal-confirm portal-field--wide"
          >
            <input
              v-model="form.confirmed_fields"
              type="checkbox"
              value="full_name"
              class="portal-checkbox"
            >
            <span>Confirm the known full name, or edit it above before continuing.</span>
          </label>

          <div class="portal-form-actions">
            <p class="portal-copy portal-copy--small">
              Only current M2 profile fields are collected.
            </p>
            <button
              type="submit"
              :disabled="form.processing"
              class="portal-button portal-button--primary"
            >
              {{ form.processing ? 'Saving…' : 'Save and continue' }}
            </button>
          </div>
        </form>

        <form
          v-else-if="props.currentStage === 'profile'"
          class="portal-stack"
          @submit.prevent="submitStage"
        >
          <p class="portal-copy">
            No medical or survey questions are collected in this milestone. Continue to preserve your progress.
          </p>
          <p
            v-if="generalError('stage')"
            class="portal-error"
            role="alert"
          >
            {{ generalError('stage') }}
          </p>
          <button
            type="submit"
            :disabled="form.processing"
            class="portal-button portal-button--primary self-start"
          >
            {{ form.processing ? 'Saving…' : 'Continue' }}
          </button>
        </form>

        <form
          v-else-if="props.currentStage === 'service'"
          class="portal-stack"
          @submit.prevent="submitStage"
        >
          <p class="portal-copy">
            Published services are shown for orientation. Booking and service-format selection are deferred to the scheduling milestone.
          </p>
          <div
            v-if="props.services.length"
            class="portal-service-grid"
          >
            <article
              v-for="service in props.services"
              :key="service.id"
              class="portal-service-card"
            >
              <h3 class="portal-heading portal-heading--card">
                {{ service.name }}
              </h3>
              <p class="portal-card__summary">
                {{ service.summary }}
              </p>
            </article>
          </div>
          <p
            v-else
            class="portal-empty"
          >
            No published services are available.
          </p>
          <button
            type="submit"
            :disabled="form.processing"
            class="portal-button portal-button--primary self-start"
          >
            {{ form.processing ? 'Saving…' : 'Continue' }}
          </button>
        </form>

        <div
          v-else
          class="portal-stack"
        >
          <p class="portal-copy">
            Goals and consent collection will appear after the approved legal and product configuration is available.
          </p>
          <p
            v-if="isBlocked"
            class="portal-notice portal-notice--warning"
            role="status"
          >
            This step is blocked by the current open question about consent text, lawful basis, retention, and versions (OQ-006).
          </p>
          <p
            v-if="generalError('goals')"
            class="portal-error"
            role="alert"
          >
            {{ generalError('goals') }}
          </p>
        </div>
      </section>
    </section>
  </main>
</template>
