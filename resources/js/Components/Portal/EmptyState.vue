<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
  <section class="portal-empty-state">
    <h2 class="portal-heading portal-heading--card">
      {{ title }}
    </h2>
    <p
      v-if="description"
      class="portal-copy portal-copy--small"
    >
      {{ description }}
    </p>
    <slot />
  </section>
</template>
