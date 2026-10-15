<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PortalIcon from './PortalIcon.vue';
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
    { key: 'bookings', label: 'shell.book' },
    { key: 'health', label: 'shell.health' },
    { key: 'companion', label: 'shell.companion' },
    { key: 'more', label: 'shell.more' },
] as const;
const bonusDialog = ref<HTMLDialogElement | null>(null);

function navigationHref(key: Exclude<PortalNavKey, null>): string {
    if (key === 'bookings') {
        return props.portal.urls.services;
    }

    if (key === 'health') {
        return props.portal.urls.health;
    }

    return props.portal.urls[key];
}

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
            :href="navigationHref(item.key)"
            class="portal-header__link"
            :class="{ 'portal-header__link--active': props.active === item.key }"
          >
            {{ portalText(portal.locale, item.label) }}
          </Link>
        </nav>

        <div class="portal-header-actions">
          <button
            v-if="portal.availableBonus"
            type="button"
            class="portal-button portal-button--secondary"
            :aria-label="portalText(portal.locale, 'bonus.title')"
            @click="bonusDialog?.showModal()"
          >
            <PortalIcon name="wallet" />
            {{ portal.availableBonus }}
          </button>
          <LanguageSwitcher :portal="portal" />
        </div>
      </div>
    </header>

    <main class="portal-main">
      <slot />
    </main>
    <dialog
      ref="bonusDialog"
      class="portal-bonus-dialog"
    >
      <div class="portal-stack">
        <div class="portal-section-heading">
          <strong>{{ portalText(portal.locale, 'bonus.title') }}</strong>
          <button
            type="button"
            class="portal-button"
            :aria-label="portalText(portal.locale, 'stories.close')"
            @click="bonusDialog?.close()"
          >
            <PortalIcon name="close" />
          </button>
        </div>
        <p class="portal-copy">
          {{ portalText(portal.locale, 'bonus.description') }}
        </p>
        <p
          v-if="portal.bonusCurrencyUnavailable"
          class="portal-copy"
        >
          {{ portalText(portal.locale, 'bonus.currencyUnavailable') }}
          <Link
            :href="portal.urls.profile"
            class="portal-link"
            @click="bonusDialog?.close()"
          >
            {{ portalText(portal.locale, 'profile.title') }}
          </Link>
        </p>
        <Link
          :href="portal.urls.services"
          class="portal-button portal-button--primary"
          @click="bonusDialog?.close()"
        >
          {{ portalText(portal.locale, 'home.book') }}
        </Link>
      </div>
    </dialog>

    <MobileBottomNavigation
      v-if="portal.authenticated && props.bottomNavigation && props.active"
      :portal="portal"
      :active="props.active"
    />
  </div>
</template>

<style scoped>
.portal-header__inner { flex-wrap: wrap; }
.portal-header-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 8px; min-width: 0; margin-left: auto; }
.portal-header-actions button { max-width: 100%; overflow-wrap: anywhere; }
.portal-bonus-dialog { width: min(400px, calc(100vw - 32px)); max-height: calc(100dvh - 32px); padding: 20px; border: 1px solid #e0d3c9; border-radius: 16px; }
</style>
