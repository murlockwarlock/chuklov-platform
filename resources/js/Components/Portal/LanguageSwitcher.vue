<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { PortalLocale, PortalShell } from '../../types/portal';
import { portalText } from '../../locales/portal';

const props = defineProps<{ portal: PortalShell }>();
const form = useForm<{ locale: PortalLocale }>({ locale: props.portal.locale });
const locale = computed<PortalLocale>(() => props.portal.locale === 'en' ? 'en' : 'ru');

function switchLocale(nextLocale: PortalLocale): void {
    if (nextLocale === locale.value || form.processing) {
        return;
    }

    form.locale = nextLocale;
    form.post(props.portal.localeUrl, {
        preserveScroll: true,
        preserveState: true,
    });
}
</script>

<template>
  <div
    class="portal-language-switcher"
    :aria-label="portalText(locale, 'shell.language')"
    role="group"
  >
    <button
      v-for="option in (['ru', 'en'] as PortalLocale[])"
      :key="option"
      type="button"
      class="portal-language-switcher__option"
      :class="{ 'portal-language-switcher__option--active': locale === option }"
      :aria-pressed="locale === option"
      :disabled="form.processing"
      @click="switchLocale(option)"
    >
      {{ option.toUpperCase() }}
    </button>
  </div>
</template>
