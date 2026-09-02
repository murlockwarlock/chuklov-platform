<script setup lang="ts">
type IconName = 'home' | 'calendar' | 'check' | 'sparkles' | 'wallet' | 'user' | 'paperclip';

defineProps<{
    name: IconName;
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
  </svg>
</template>
