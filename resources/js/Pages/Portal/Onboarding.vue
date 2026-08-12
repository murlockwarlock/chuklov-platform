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
  <main class="min-h-screen bg-stone-950 text-stone-100">
    <section class="mx-auto flex max-w-5xl flex-col gap-8 px-5 py-10 sm:px-8 sm:py-14">
      <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p class="text-xs font-semibold tracking-[0.2em] text-amber-300 uppercase">
            Client portal
          </p>
          <h1 class="mt-3 text-3xl font-semibold tracking-tight sm:text-5xl">
            Onboarding
          </h1>
          <p class="mt-3 max-w-2xl text-sm leading-6 text-stone-300">
            Flow {{ props.flowVersion }}. Known information is shown for confirmation, and later steps stay limited to the current milestone.
          </p>
        </div>
        <Link
          href="/"
          class="inline-flex min-h-11 items-center justify-center rounded-xl border border-stone-700 px-4 py-2 text-sm font-medium text-stone-200 transition hover:border-stone-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-200"
        >
          Back to portal
        </Link>
      </header>

      <ol
        class="grid gap-3 sm:grid-cols-4"
        aria-label="Onboarding progress"
      >
        <li
          v-for="(stage, index) in props.stages"
          :key="stage"
          class="rounded-xl border p-4"
          :class="index <= currentStageIndex ? 'border-amber-300/50 bg-amber-100/10' : 'border-stone-800 bg-stone-900'"
        >
          <p class="text-xs font-semibold text-stone-500">
            {{ index + 1 }}
          </p>
          <p
            class="mt-2 text-sm font-medium"
            :class="index <= currentStageIndex ? 'text-amber-100' : 'text-stone-400'"
          >
            {{ labels[stage] }}
          </p>
        </li>
      </ol>

      <section
        class="rounded-2xl border border-stone-800 bg-stone-900 p-6 sm:p-8"
        aria-labelledby="stage-heading"
      >
        <div class="flex flex-col gap-2">
          <p class="text-xs font-semibold tracking-[0.2em] text-amber-300 uppercase">
            Current step
          </p>
          <h2
            id="stage-heading"
            class="text-2xl font-semibold text-stone-100"
          >
            {{ labels[props.currentStage] }}
          </h2>
        </div>

        <form
          v-if="props.currentStage === 'contacts'"
          class="mt-8 grid gap-5 md:grid-cols-2"
          @submit.prevent="submitStage"
        >
          <div class="md:col-span-2">
            <label
              for="full_name"
              class="text-sm font-medium text-stone-200"
            >Full name</label>
            <input
              id="full_name"
              v-model="form.full_name"
              required
              autocomplete="name"
              class="mt-2 min-h-11 w-full rounded-xl border border-stone-700 bg-stone-950 px-4 text-stone-100 outline-none focus:border-amber-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-200"
            >
            <p
              v-if="fieldError('full_name')"
              class="mt-2 text-sm text-red-300"
            >
              {{ fieldError('full_name') }}
            </p>
          </div>

          <div
            v-for="field in ['email', 'phone', 'language', 'timezone', 'lead_source', 'referral_code']"
            :key="field"
          >
            <label
              :for="field"
              class="text-sm font-medium text-stone-200"
            >
              {{ field.replace('_', ' ') }}
              <span
                v-if="props.missingFields.includes(field)"
                class="text-stone-500"
              >(optional)</span>
            </label>
            <input
              :id="field"
              v-model="form[field as keyof OnboardingForm]"
              :type="field === 'email' ? 'email' : 'text'"
              :autocomplete="field === 'email' ? 'email' : field === 'phone' ? 'tel' : undefined"
              class="mt-2 min-h-11 w-full rounded-xl border border-stone-700 bg-stone-950 px-4 text-stone-100 outline-none focus:border-amber-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-200"
            >
            <p
              v-if="fieldError(field as keyof OnboardingForm)"
              class="mt-2 text-sm text-red-300"
            >
              {{ fieldError(field as keyof OnboardingForm) }}
            </p>
            <label
              v-if="props.knownFields.includes(field)"
              class="mt-3 flex items-start gap-3 text-sm text-stone-400"
            >
              <input
                v-model="form.confirmed_fields"
                type="checkbox"
                :value="field"
                class="mt-1 accent-amber-300"
              >
              <span>Confirm this known value</span>
            </label>
          </div>

          <label
            v-if="props.knownFields.includes('full_name')"
            class="flex items-start gap-3 text-sm text-stone-400 md:col-span-2"
          >
            <input
              v-model="form.confirmed_fields"
              type="checkbox"
              value="full_name"
              class="mt-1 accent-amber-300"
            >
            <span>Confirm the known full name, or edit it above before continuing.</span>
          </label>

          <div class="flex flex-col gap-3 md:col-span-2 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-stone-500">
              Only current M2 profile fields are collected.
            </p>
            <button
              type="submit"
              :disabled="form.processing"
              class="inline-flex min-h-11 items-center justify-center rounded-xl bg-amber-300 px-5 py-3 font-semibold text-stone-950 transition hover:bg-amber-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-200 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {{ form.processing ? 'Saving…' : 'Save and continue' }}
            </button>
          </div>
        </form>

        <form
          v-else-if="props.currentStage === 'profile'"
          class="mt-8 flex flex-col gap-6"
          @submit.prevent="submitStage"
        >
          <p class="max-w-2xl leading-7 text-stone-300">
            No medical or survey questions are collected in this milestone. Continue to preserve your progress.
          </p>
          <p
            v-if="generalError('stage')"
            class="text-sm text-red-300"
            role="alert"
          >
            {{ generalError('stage') }}
          </p>
          <button
            type="submit"
            :disabled="form.processing"
            class="self-start rounded-xl bg-amber-300 px-5 py-3 font-semibold text-stone-950 transition hover:bg-amber-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-200 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {{ form.processing ? 'Saving…' : 'Continue' }}
          </button>
        </form>

        <form
          v-else-if="props.currentStage === 'service'"
          class="mt-8 flex flex-col gap-6"
          @submit.prevent="submitStage"
        >
          <p class="max-w-2xl leading-7 text-stone-300">
            Published services are shown for orientation. Booking and service-format selection are deferred to the scheduling milestone.
          </p>
          <div
            v-if="props.services.length"
            class="grid gap-4 md:grid-cols-2"
          >
            <article
              v-for="service in props.services"
              :key="service.id"
              class="rounded-xl border border-stone-800 bg-stone-950 p-5"
            >
              <h3 class="font-medium text-amber-100">
                {{ service.name }}
              </h3>
              <p class="mt-2 text-sm leading-6 text-stone-400">
                {{ service.summary }}
              </p>
            </article>
          </div>
          <p
            v-else
            class="rounded-xl border border-dashed border-stone-700 p-5 text-sm text-stone-500"
          >
            No published services are available.
          </p>
          <button
            type="submit"
            :disabled="form.processing"
            class="self-start rounded-xl bg-amber-300 px-5 py-3 font-semibold text-stone-950 transition hover:bg-amber-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-200 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {{ form.processing ? 'Saving…' : 'Continue' }}
          </button>
        </form>

        <div
          v-else
          class="mt-8 flex flex-col gap-4"
        >
          <p class="leading-7 text-stone-300">
            Goals and consent collection will appear after the approved legal and product configuration is available.
          </p>
          <p
            v-if="isBlocked"
            class="rounded-xl border border-amber-300/30 bg-amber-100/10 p-4 text-sm leading-6 text-amber-100"
            role="status"
          >
            This step is blocked by the current open question about consent text, lawful basis, retention, and versions (OQ-006).
          </p>
          <p
            v-if="generalError('goals')"
            class="text-sm text-red-300"
            role="alert"
          >
            {{ generalError('goals') }}
          </p>
        </div>
      </section>
    </section>
  </main>
</template>
