<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import LanguageSwitcher from './LanguageSwitcher.vue';
import PortalIcon from './PortalIcon.vue';
import { resolveClientRuntime } from '../../runtime/clientRuntime';
import type { PortalShell } from '../../types/portal';

withDefaults(defineProps<{
    title: string;
    description?: string | null;
    eyebrow?: string | null;
    backHref?: string | null;
    backLabel?: string | null;
    headingLevel?: 'page' | 'section';
}>(), {
    description: null,
    eyebrow: null,
    backHref: null,
    backLabel: null,
    headingLevel: 'section',
});
const page = usePage<{ portal: PortalShell }>();
const runtimeMode = typeof window !== 'undefined' ? resolveClientRuntime() : 'web';
const showTelegramLanguage = computed(() => runtimeMode === 'telegram-mini-app' && page.props.portal.authenticated);
</script>

<template>
  <header class="portal-page-header">
    <div class="portal-page-header__main">
      <Link
        v-if="backHref && backLabel"
        :href="backHref"
        class="portal-context-back"
      >
        <PortalIcon
          name="arrow"
          class="portal-context-back__icon"
        />
        <span>{{ backLabel }}</span>
      </Link>
      <div class="portal-page-header__copy">
        <p
          v-if="eyebrow"
          class="portal-eyebrow"
        >
          {{ eyebrow }}
        </p>
        <h1
          class="portal-heading"
          :class="headingLevel === 'page' ? 'portal-heading--page' : 'portal-heading--section'"
        >
          {{ title }}
        </h1>
        <p
          v-if="description"
          class="portal-page-header__description portal-copy"
        >
          {{ description }}
        </p>
        <slot />
      </div>
    </div>
    <div
      v-if="$slots.actions || showTelegramLanguage"
      class="portal-page-header__actions"
    >
      <LanguageSwitcher
        v-if="showTelegramLanguage"
        :portal="page.props.portal"
      />
      <slot name="actions" />
    </div>
  </header>
</template>
