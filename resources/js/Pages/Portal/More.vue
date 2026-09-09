<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import PortalIcon from '../../Components/Portal/PortalIcon.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

const props = defineProps<{ portal: PortalShell }>();
const { t } = usePortalLocale();
</script>

<template>
  <AppShell
    :title="t('more.title')"
    :portal="props.portal"
    active="more"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-stack portal-stack--tight">
        <h1 class="portal-heading portal-heading--section">
          {{ t('more.title') }}
        </h1>
      </header>

      <nav
        class="portal-list"
        :aria-label="t('more.title')"
      >
        <Link
          :href="props.portal.urls.bookings"
          class="portal-list__row"
        >
          <strong class="portal-list__title">{{ t('bookings.title') }}</strong>
          <PortalIcon
            name="arrow"
            class="portal-list__chevron"
          />
        </Link>
        <Link
          :href="props.portal.urls.profile"
          class="portal-list__row"
        >
          <strong class="portal-list__title">{{ t('more.profile') }}</strong>
          <PortalIcon
            name="arrow"
            class="portal-list__chevron"
          />
        </Link>
        <Link
          :href="props.portal.urls.finance"
          class="portal-list__row"
        >
          <strong class="portal-list__title">{{ t('more.finance') }}</strong>
          <PortalIcon
            name="arrow"
            class="portal-list__chevron"
          />
        </Link>
        <Link
          :href="props.portal.urls.referrals"
          class="portal-list__row"
          data-testid="more-partnership-link"
        >
          <strong class="portal-list__title">{{ t('more.partnership') }}</strong>
          <PortalIcon
            name="arrow"
            class="portal-list__chevron"
          />
        </Link>
        <Link
          :href="props.portal.urls.feedback"
          class="portal-list__row"
        >
          <strong class="portal-list__title">{{ t('more.feedback') }}</strong>
          <PortalIcon
            name="arrow"
            class="portal-list__chevron"
          />
        </Link>
        <Link
          :href="props.portal.urls.b2b"
          class="portal-list__row"
        >
          <strong class="portal-list__title">{{ t('more.business') }}</strong>
          <PortalIcon
            name="arrow"
            class="portal-list__chevron"
          />
        </Link>
      </nav>
    </section>
  </AppShell>
</template>
