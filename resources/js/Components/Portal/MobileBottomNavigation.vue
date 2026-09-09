<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PortalIcon from './PortalIcon.vue';
import type { PortalNavKey, PortalShell } from '../../types/portal';
import { portalText } from '../../locales/portal';

const props = defineProps<{
    portal: PortalShell;
    active: Exclude<PortalNavKey, null>;
}>();

const items = [
    { key: 'home', label: 'shell.home', icon: 'home' },
    { key: 'bookings', label: 'shell.bookings', icon: 'calendar' },
    { key: 'health', label: 'shell.health', icon: 'health' },
    { key: 'companion', label: 'shell.companion', icon: 'sparkles' },
    { key: 'more', label: 'shell.more', icon: 'more' },
] as const;
</script>

<template>
  <nav
    class="portal-bottom-nav"
    :aria-label="portalText(portal.locale, 'shell.openNavigation')"
  >
    <Link
      v-for="item in items"
      :key="item.key"
      :href="portal.urls[item.key]"
      class="portal-bottom-nav__link"
      :class="{ 'portal-bottom-nav__link--active': props.active === item.key }"
    >
      <span class="portal-bottom-nav__icon">
        <PortalIcon :name="item.icon" />
      </span>
      <span class="portal-bottom-nav__label">{{ portalText(portal.locale, item.label) }}</span>
    </Link>
  </nav>
</template>
