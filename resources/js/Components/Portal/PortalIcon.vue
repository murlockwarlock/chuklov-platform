<script setup lang="ts">
import type { PortalIconName } from '../../types/portal';

defineProps<{
    name: PortalIconName;
}>();
</script>

<template>
  <svg
    class="portal-icon"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <path
      v-if="name === 'home'"
      d="m3.5 10.8 8.5-7 8.5 7"
    />
    <path
      v-if="name === 'home'"
      d="M5.5 9.5v10h13v-10M9.5 19.5v-5h5v5"
    />
    <template v-if="name === 'calendar'">
      <rect
        x="3.5"
        y="5"
        width="17"
        height="15"
        rx="2"
      />
      <path d="M7.5 3.5v3M16.5 3.5v3M3.5 9h17" />
      <path d="M8 12.5h.01M12 12.5h.01M16 12.5h.01M8 16h.01M12 16h.01" />
    </template>
    <template v-if="name === 'check'">
      <rect
        x="4"
        y="3.5"
        width="16"
        height="17"
        rx="2"
      />
      <path d="M8 3.5v3h8v-3M8 12l2.25 2.25L16 8.5" />
    </template>
    <template v-if="name === 'sparkles'">
      <path d="m12 3 1.25 4.75L18 9l-4.75 1.25L12 15l-1.25-4.75L6 9l4.75-1.25L12 3Z" />
      <path d="m18.5 14 .65 2.35L21.5 17l-2.35.65L18.5 20l-.65-2.35L15.5 17l2.35-.65L18.5 14ZM5.5 14l.45 1.55L7.5 16l-1.55.45L5.5 18l-.45-1.55L3.5 16l1.55-.45L5.5 14Z" />
    </template>
    <template v-if="name === 'wallet'">
      <path d="M4.5 6.5h13a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2Z" />
      <path d="M3.5 9h14.75a1.75 1.75 0 0 1 1.75 1.75v3.5h-4.25a2.25 2.25 0 0 1 0-4.5H20M15.5 12h.01" />
      <path d="M5 6.5V5.75A1.75 1.75 0 0 1 6.75 4h8.5a1.75 1.75 0 0 1 1.65 1.17" />
    </template>
    <template v-if="name === 'user'">
      <circle
        cx="12"
        cy="8"
        r="3.25"
      />
      <path d="M5 20a7 7 0 0 1 14 0" />
    </template>
    <path
      v-if="name === 'paperclip'"
      d="m9.5 12.5 5.25-5.25a3.18 3.18 0 0 1 4.5 4.5L11 20a5 5 0 0 1-7.07-7.07l8-8a3.5 3.5 0 0 1 4.95 4.95L8.7 18.06a2 2 0 1 1-2.83-2.83l7.42-7.42"
    />
    <template v-if="name === 'info'">
      <circle
        cx="12"
        cy="12"
        r="8.5"
      />
      <path d="M12 10.5v5M12 7.5h.01" />
    </template>
    <path
      v-if="name === 'refresh'"
      d="M19 8.5V4.75h-3.75M19 4.75a8.5 8.5 0 1 0 1.25 8.75"
    />
    <template v-if="name === 'health'">
      <path d="M4 12h3l1.5-4 3 8 1.5-4H20" />
      <path d="M12 21s-7.5-4.35-7.5-10.2A4.3 4.3 0 0 1 12 8.2a4.3 4.3 0 0 1 7.5 2.6C19.5 16.65 12 21 12 21Z" />
    </template>
    <template v-if="name === 'more'">
      <circle
        cx="5"
        cy="12"
        r="1"
      />
      <circle
        cx="12"
        cy="12"
        r="1"
      />
      <circle
        cx="19"
        cy="12"
        r="1"
      />
    </template>
    <path
      v-if="name === 'arrow'"
      d="M4 12h15M13 6l6 6-6 6"
    />
    <path
      v-if="name === 'close'"
      d="m6 6 12 12M18 6 6 18"
    />
    <template v-if="name === 'map'">
      <path d="m3.5 5.5 5.67-2 5.66 2 5.67-2v15l-5.67 2-5.66-2-5.67 2v-15Z" />
      <path d="M9.17 3.5v15M14.83 5.5v15" />
    </template>
    <template v-if="name === 'pin'">
      <path d="M19 10.25c0 5-7 10.25-7 10.25s-7-5.25-7-10.25a7 7 0 1 1 14 0Z" />
      <circle
        cx="12"
        cy="10.25"
        r="2.25"
      />
    </template>
    <template v-if="name === 'compass'">
      <circle
        cx="12"
        cy="12"
        r="8.5"
      />
      <path d="m15.5 8.5-2.1 4.9-4.9 2.1 2.1-4.9 4.9-2.1Z" />
    </template>
    <template v-if="name === 'globe'">
      <circle
        cx="12"
        cy="12"
        r="8.5"
      />
      <path d="M3.8 12h16.4M12 3.5c2.1 2.3 3.2 5.1 3.2 8.5s-1.1 6.2-3.2 8.5c-2.1-2.3-3.2-5.1-3.2-8.5S9.9 5.8 12 3.5Z" />
    </template>
  </svg>
</template>
