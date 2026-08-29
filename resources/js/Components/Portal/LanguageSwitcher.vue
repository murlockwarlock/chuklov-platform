<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import type { PortalLocale, PortalShell } from '../../types/portal';
import { portalText } from '../../locales/portal';

const props = defineProps<{ portal: PortalShell }>();
const form = useForm<{ locale: PortalLocale }>({ locale: props.portal.locale });
const locale = computed<PortalLocale>(() => props.portal.locale === 'en' ? 'en' : 'ru');
const open = ref(false);
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);

function closeMenu(restoreFocus = false): void {
    open.value = false;

    if (restoreFocus) {
        trigger.value?.focus();
    }
}

function toggleMenu(): void {
    if (!form.processing) {
        open.value = !open.value;
    }
}

function handleDocumentClick(event: MouseEvent): void {
    if (root.value && event.target instanceof Node && !root.value.contains(event.target)) {
        closeMenu();
    }
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        closeMenu(true);
    }
}

function switchLocale(nextLocale: PortalLocale): void {
    if (nextLocale === locale.value || form.processing) {
        return;
    }

    form.locale = nextLocale;
    closeMenu();
    form.post(props.portal.localeUrl, {
        preserveScroll: true,
        preserveState: true,
    });
}

onMounted(() => {
    document.addEventListener('click', handleDocumentClick);
    document.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    document.removeEventListener('click', handleDocumentClick);
    document.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
  <div
    ref="root"
    class="portal-language-switcher"
    :aria-label="portalText(locale, 'shell.language')"
    :class="{ 'portal-language-switcher--open': open }"
  >
    <button
      ref="trigger"
      type="button"
      class="portal-language-switcher__trigger"
      :aria-expanded="open"
      aria-haspopup="menu"
      :aria-label="portalText(locale, locale === 'ru' ? 'shell.russian' : 'shell.english')"
      :title="portalText(locale, locale === 'ru' ? 'shell.russian' : 'shell.english')"
      :disabled="form.processing"
      @click="toggleMenu"
    >
      <span aria-hidden="true">{{ locale === 'ru' ? '🇷🇺' : '🇬🇧' }}</span>
      <span
        class="portal-language-switcher__chevron"
        aria-hidden="true"
      >⌄</span>
    </button>
    <div
      v-if="open"
      class="portal-language-switcher__menu"
      role="menu"
      :aria-label="portalText(locale, 'shell.language')"
    >
      <button
        v-for="option in (['ru', 'en'] as PortalLocale[])"
        :key="option"
        type="button"
        class="portal-language-switcher__menu-option"
        :class="{ 'portal-language-switcher__menu-option--active': locale === option }"
        role="menuitemradio"
        :aria-checked="locale === option"
        :disabled="form.processing"
        @click="switchLocale(option)"
      >
        <span aria-hidden="true">{{ option === 'ru' ? '🇷🇺' : '🇬🇧' }}</span>
        <span>{{ portalText(locale, option === 'ru' ? 'shell.russian' : 'shell.english') }}</span>
        <span
          v-if="locale === option"
          class="portal-language-switcher__check"
          aria-hidden="true"
        >✓</span>
      </button>
    </div>
  </div>
</template>
