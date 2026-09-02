<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import LanguageSwitcher from './LanguageSwitcher.vue';
import MobileBottomNavigation from './MobileBottomNavigation.vue';
import { portalText } from '../../locales/portal';
import type { PortalNavKey, PortalShell } from '../../types/portal';

const props = withDefaults(defineProps<{
    title: string;
    portal: PortalShell;
    active?: PortalNavKey;
    bottomNavigation?: boolean;
}>(), {
    active: null,
    bottomNavigation: true,
});

const navigation = [
    { key: 'home', label: 'shell.home' },
    { key: 'services', label: 'shell.services' },
    { key: 'bookings', label: 'shell.bookings' },
    { key: 'finance', label: 'shell.finance' },
    { key: 'surveys', label: 'shell.surveys' },
    { key: 'companion', label: 'shell.companion' },
    { key: 'referrals', label: 'shell.referrals' },
    { key: 'feedback', label: 'shell.feedback' },
    { key: 'profile', label: 'shell.profile' },
    { key: 'b2b', label: 'shell.b2b' },
] as const;

const brandLogo = computed(() => props.portal.locale === 'en'
    ? '/brand/chuklov-designer-logo-en.jpg'
    : '/brand/chuklov-designer-logo-ru.jpg');
</script>

<template>
  <div class="portal-shell">
    <Head :title="props.title" />

    <header class="portal-header">
      <div class="portal-header__inner">
        <Link
          :href="portal.authenticated ? portal.urls.home : '/'"
          class="portal-brand"
          aria-label="CHUKLOV"
        >
          <img
            :src="brandLogo"
            alt="CHUKLOV"
            class="portal-brand__logo"
          >
        </Link>

        <nav
          v-if="portal.authenticated"
          class="portal-header__navigation"
          :aria-label="portalText(portal.locale, 'shell.openNavigation')"
        >
          <Link
            v-for="item in navigation"
            :key="item.key"
            :href="portal.urls[item.key]"
            class="portal-header__link"
            :class="{ 'portal-header__link--active': props.active === item.key }"
          >
            {{ portalText(portal.locale, item.label) }}
          </Link>
        </nav>

        <LanguageSwitcher :portal="portal" />
      </div>
    </header>

    <main class="portal-main">
      <slot />
    </main>

    <MobileBottomNavigation
      v-if="portal.authenticated && props.bottomNavigation && props.active"
      :portal="portal"
      :active="props.active"
    />
  </div>
</template>
