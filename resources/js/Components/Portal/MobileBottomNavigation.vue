<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { PortalNavKey, PortalShell } from '../../types/portal';
import { portalText } from '../../locales/portal';

const props = defineProps<{
    portal: PortalShell;
    active: Exclude<PortalNavKey, null>;
}>();

const items = [
    { key: 'home', label: 'shell.home', icon: '⌂' },
    { key: 'bookings', label: 'shell.bookings', icon: '▣' },
    { key: 'surveys', label: 'shell.surveys', icon: '✓' },
    { key: 'companion', label: 'shell.companion', icon: '✦' },
    { key: 'finance', label: 'shell.finance', icon: '₽' },
    { key: 'profile', label: 'shell.profile', icon: '○' },
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
      <span
        class="portal-bottom-nav__icon"
        aria-hidden="true"
      >{{ item.icon }}</span>
      <span>{{ portalText(portal.locale, item.label) }}</span>
    </Link>
  </nav>
</template>
