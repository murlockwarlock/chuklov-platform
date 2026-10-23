<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import PortalIcon from './PortalIcon.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';

type Story = { key: string; title: string; videoUrl: string | null; previewUrl: string | null; subtitlesUrl: string | null };
const props = defineProps<{ stories: Story[] }>();
const { t, locale } = usePortalLocale();
const availableStories = computed(() => props.stories.filter((story) => story.videoUrl !== null));
const active = ref<number | null>(null);
const dialog = ref<HTMLDialogElement | null>(null);
const selected = computed(() => active.value === null ? null : availableStories.value[active.value]);
const canPrevious = computed(() => active.value !== null && active.value > 0);
const canNext = computed(() => active.value !== null && active.value < availableStories.value.length - 1);
const playbackError = ref(false);
let touchStart = 0;

async function open(index: number): Promise<void> {
    if (!availableStories.value[index]?.videoUrl) return;
    active.value = index;
    playbackError.value = false;
    await nextTick();
    dialog.value?.showModal();
}
function close(): void {
    dialog.value?.close();
    active.value = null;
}
function move(direction: number): void {
    if (active.value === null) return;
    for (let index = active.value + direction; index >= 0 && index < availableStories.value.length; index += direction) {
        if (availableStories.value[index]?.videoUrl) {
            active.value = index;
            playbackError.value = false;
            return;
        }
    }
}
function swipe(event: TouchEvent): void {
    const distance = (event.changedTouches[0]?.clientX ?? touchStart) - touchStart;
    if (Math.abs(distance) > 50) move(distance > 0 ? -1 : 1);
}
</script>

<template>
  <div
    v-if="availableStories.length > 0"
    class="portal-stories"
    :aria-label="t('stories.title')"
  >
    <button
      v-for="(story, index) in availableStories"
      :key="story.key"
      type="button"
      class="portal-story"
      :aria-label="story.title"
      :title="story.title"
      :disabled="!story.videoUrl"
      @click="open(index)"
    >
      <span class="portal-story__preview">
        <img
          v-if="story.previewUrl"
          :src="story.previewUrl"
          alt=""
        >
        <PortalIcon
          v-else
          name="user"
        />
      </span>
      <span class="portal-story__title">{{ story.title }}</span>
    </button>
  </div>
  <dialog
    ref="dialog"
    class="portal-story-player"
    @cancel="active = null"
    @close="active = null"
    @keydown.left.prevent="move(-1)"
    @keydown.right.prevent="move(1)"
    @touchstart.passive="touchStart = $event.touches[0]?.clientX ?? 0"
    @touchend.passive="swipe"
  >
    <template v-if="selected">
      <div class="portal-story-player__header">
        <strong>{{ selected.title }}</strong>
        <button
          type="button"
          :aria-label="t('stories.close')"
          class="portal-button"
          @click="close"
        >
          <PortalIcon name="close" />
        </button>
      </div>
      <video
        :key="selected.key"
        :src="selected.videoUrl ?? undefined"
        :poster="selected.previewUrl ?? undefined"
        crossorigin="anonymous"
        controls
        autoplay
        playsinline
        class="portal-story-player__video"
        @error="playbackError = true"
        @canplay="playbackError = false"
      >
        <track
          v-if="selected.subtitlesUrl"
          kind="subtitles"
          :src="selected.subtitlesUrl"
          :srclang="locale"
          default
        >
      </video>
      <p
        v-if="playbackError"
        role="alert"
      >
        {{ t('stories.playbackError') }}
      </p>
      <nav class="portal-story-player__navigation">
        <button
          type="button"
          class="portal-button"
          :disabled="!canPrevious"
          @click="move(-1)"
        >
          {{ t('stories.previous') }}
        </button>
        <span>{{ (active ?? 0) + 1 }} / {{ availableStories.length }}</span>
        <button
          type="button"
          class="portal-button"
          :disabled="!canNext"
          @click="move(1)"
        >
          {{ t('stories.next') }}
        </button>
      </nav>
    </template>
  </dialog>
</template>

<style scoped>
.portal-stories { display: flex; align-items: flex-start; gap: 12px; width: 100%; max-width: 100%; height: 90px; max-height: 90px; overflow-x: auto; overflow-y: hidden; }
.portal-story { flex: 0 0 76px; min-width: 0; height: 90px; display: flex; flex-direction: column; align-items: center; gap: 3px; font-size: 10px; line-height: 12px; }
.portal-story:disabled { opacity: .55; }
.portal-story__preview { position: relative; display: grid; place-items: center; width: 48px; height: 48px; box-sizing: border-box; flex-shrink: 0; overflow: hidden; border-radius: 50%; background: #f0e5dd; border: 2px solid #b88059; }
.portal-story__preview img { position: absolute; inset: 0; display: block; width: 100%; height: 100%; max-width: 100%; max-height: 100%; object-fit: cover; }
.portal-story__title { display: -webkit-box; max-width: 100%; overflow: hidden; overflow-wrap: anywhere; text-align: center; -webkit-box-orient: vertical; -webkit-line-clamp: 3; }
.portal-story-player { width: 100vw; height: 100dvh; max-width: none; max-height: none; margin: 0; padding: 12px; border: 0; background: #171717; color: white; }
.portal-story-player[open] { display: flex; flex-direction: column; gap: 12px; }
.portal-story-player__header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.portal-story-player__header strong { min-width: 0; overflow-wrap: anywhere; }
.portal-story-player__header button { flex-shrink: 0; }
.portal-story-player__video { flex: 1; min-height: 0; width: 100%; max-width: 100%; object-fit: contain; }
.portal-story-player__navigation { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
</style>
