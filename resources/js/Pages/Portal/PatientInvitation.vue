<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PatientSummary } from '../../types/patients';
import type { PortalShell } from '../../types/portal';

const props = defineProps<{ portal: PortalShell; patient: PatientSummary; accepted: boolean; urls: { accept: string; profile: string; family: string } }>();
const { t } = usePortalLocale();
const form = useForm({ confirm_identity: false });
const invitationError = computed(() => Object.entries(form.errors).find(([key]) => key === 'invitation')?.[1]);
</script>

<template>
  <AppShell
    :title="t('family.invitationTitle')"
    :portal="props.portal"
  >
    <main class="portal-container portal-stack portal-stack--loose">
      <h1 class="portal-heading">
        {{ t('family.invitationTitle') }}
      </h1>
      <h2 class="portal-heading portal-heading--section break-words">
        {{ props.patient.name }}
      </h2>
      <p class="portal-copy">
        {{ props.patient.birthDate }}
      </p>
      <template v-if="props.accepted">
        <p class="portal-notice portal-notice--success">
          {{ t('family.accepted') }}
        </p>
        <Link
          v-if="props.patient.medicalUrl"
          :href="props.patient.medicalUrl"
          class="portal-button portal-button--primary"
        >
          {{ t('more.medicalProfile') }}
        </Link>
        <Link
          :href="props.urls.family"
          class="portal-link"
        >
          {{ t('family.title') }}
        </Link>
      </template>
      <form
        v-else
        class="portal-stack"
        @submit.prevent="form.post(props.urls.accept)"
      >
        <p class="portal-copy">
          {{ t('family.acceptPrivacy') }}
        </p>
        <label class="portal-checkbox-row"><input
          v-model="form.confirm_identity"
          type="checkbox"
          required
        ><span>{{ t('family.confirmIdentity') }}</span></label>
        <p
          v-if="form.errors.confirm_identity"
          class="portal-error"
        >
          {{ form.errors.confirm_identity }}
        </p>
        <p
          v-if="invitationError"
          class="portal-error"
          role="alert"
        >
          {{ invitationError }}
        </p>
        <button
          type="submit"
          :disabled="form.processing"
          class="portal-button portal-button--primary"
        >
          {{ t('family.accept') }}
        </button>
        <Link
          :href="props.urls.profile"
          class="portal-link"
        >
          {{ t('profile.title') }}
        </Link>
      </form>
    </main>
  </AppShell>
</template>
