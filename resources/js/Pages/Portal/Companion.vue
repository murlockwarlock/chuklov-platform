<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import PortalIcon from '../../Components/Portal/PortalIcon.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type TimelineItem = {
    type: 'message' | 'handoff';
    id: number | string;
    role: 'client' | 'ai' | 'staff' | 'system';
    roleLabel: string;
    content: string;
    occurredAt: string;
    transportLabel: string | null;
    feedback: 'helpful' | 'not_helpful' | null;
    attachmentCount: number;
    traceUrl: null;
};

type CompanionState = {
    messages: TimelineItem[];
    hasOlder: boolean;
    nextBeforeMessageId: number | null;
    state: 'ai_active' | 'human_handoff';
    stateLabel: string;
    pending: boolean;
    canReinspectRecentImages: boolean;
    openEscalation: { reasonLabel: string; openedAt: string } | null;
};

const props = defineProps<{
    portal: PortalShell;
    companion: CompanionState;
    urls: { send: string; feedback: string; reset: string; history: string };
}>();

const { t, locale } = usePortalLocale();
const body = ref('');
const olderLoading = ref(false);
const imageInput = ref<HTMLInputElement | null>(null);
const sendForm = useForm<{ body: string; idempotency_key: string; images: File[]; reinspect_recent_images: boolean }>({
    body: '',
    idempotency_key: '',
    images: [],
    reinspect_recent_images: false,
});
let poller: number | undefined;

function newIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function send(): void {
    const text = body.value.trim();
    if ((!text && sendForm.images.length === 0) || sendForm.processing) {
        return;
    }
    sendForm.body = text;
    sendForm.reinspect_recent_images = sendForm.images.length === 0 && sendForm.reinspect_recent_images;
    sendForm.idempotency_key = newIdempotencyKey();
    sendForm.post(props.urls.send, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            body.value = '';
            sendForm.reinspect_recent_images = false;
        },
    });
}

function selectImages(event: Event): void {
    const input = event.target as HTMLInputElement;
    sendForm.images = input.files ? Array.from(input.files).slice(0, 10) : [];
    if (sendForm.images.length) {
        sendForm.reinspect_recent_images = false;
    }
}

function openImagePicker(): void {
    if (!sendForm.processing) {
        imageInput.value?.click();
    }
}

function loadOlder(): void {
    if (!props.companion.nextBeforeMessageId || olderLoading.value) {
        return;
    }
    olderLoading.value = true;
    router.get(props.urls.history, { before: props.companion.nextBeforeMessageId }, {
        preserveScroll: true,
        only: ['companion'],
        onFinish: () => {
            olderLoading.value = false;
        },
    });
}

function submitFeedback(message: TimelineItem, value: 'helpful' | 'not_helpful'): void {
    if (message.type !== 'message' || message.role !== 'ai') {
        return;
    }
    router.post(props.urls.feedback.replace('__id__', String(message.id)), { value }, { preserveScroll: true });
}

function resetContext(): void {
    if (window.confirm(t('companion.resetConfirm'))) {
        router.post(props.urls.reset, {}, { preserveScroll: true });
    }
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

onMounted(() => {
    poller = window.setInterval(() => {
        if (props.companion.pending) {
            router.reload({ only: ['companion'] });
        }
    }, 4000);
});

onUnmounted(() => {
    if (poller !== undefined) {
        window.clearInterval(poller);
    }
});
</script>

<template>
  <AppShell
    :title="t('companion.title')"
    :portal="props.portal"
    active="companion"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            CHUKLOV
          </p>
          <h1 class="portal-heading portal-heading--section">
            {{ t('companion.title') }}
          </h1>
          <p class="portal-copy">
            {{ t('companion.description') }}
          </p>
        </div>
        <button
          class="portal-button portal-button--secondary"
          type="button"
          @click="resetContext"
        >
          {{ t('companion.reset') }}
        </button>
      </header>

      <section
        class="portal-companion"
        aria-live="polite"
      >
        <div class="portal-companion__history">
          <button
            v-if="props.companion.hasOlder"
            class="portal-button portal-button--secondary self-center"
            type="button"
            :disabled="olderLoading"
            @click="loadOlder"
          >
            {{ olderLoading ? t('common.loading') : t('companion.loadOlder') }}
          </button>

          <EmptyState
            v-if="!props.companion.messages.length"
            :title="t('companion.empty')"
          />

          <article
            v-for="message in props.companion.messages"
            :key="`${message.type}-${message.id}`"
            class="portal-companion__message"
            :class="`portal-companion__message--${message.role}`"
          >
            <div class="mb-2 flex flex-wrap items-center gap-2 text-xs font-semibold text-[var(--portal-color-ink-soft)]">
              <span>{{ message.role === 'client' ? t('companion.client') : message.role === 'ai' ? t('companion.ai') : message.role === 'staff' ? t('companion.staff') : message.roleLabel }}</span>
              <span v-if="message.transportLabel">· {{ message.transportLabel }}</span>
              <span>· {{ formatDate(message.occurredAt) }}</span>
            </div>
            <p class="m-0 text-[var(--portal-color-ink)]">
              {{ message.content }}
            </p>
            <p
              v-if="message.attachmentCount"
              class="mt-2 text-sm text-[var(--portal-color-ink-soft)]"
            >
              {{ t('companion.images', { count: message.attachmentCount }) }}
            </p>
            <div
              v-if="message.type === 'message' && message.role === 'ai'"
              class="mt-3 flex flex-wrap gap-2"
            >
              <button
                class="portal-button portal-button--secondary"
                type="button"
                :class="{ 'opacity-60': message.feedback === 'helpful' }"
                @click="submitFeedback(message, 'helpful')"
              >
                {{ t('companion.feedbackHelpful') }}
              </button>
              <button
                class="portal-button portal-button--secondary"
                type="button"
                :class="{ 'opacity-60': message.feedback === 'not_helpful' }"
                @click="submitFeedback(message, 'not_helpful')"
              >
                {{ t('companion.feedbackNotHelpful') }}
              </button>
            </div>
          </article>

          <div
            v-if="props.companion.pending"
            class="portal-copy portal-copy--small"
          >
            {{ t('companion.pending') }}
          </div>
          <div
            v-if="props.companion.state === 'human_handoff'"
            class="portal-panel"
            role="status"
          >
            <p class="portal-copy">
              {{ t('companion.paused') }}
            </p>
            <p
              v-if="props.companion.openEscalation"
              class="portal-copy portal-copy--small"
            >
              {{ props.companion.openEscalation.reasonLabel }}
            </p>
          </div>
        </div>

        <form
          class="portal-companion__composer"
          @submit.prevent="send"
        >
          <textarea
            v-model="body"
            class="portal-companion__textarea"
            :placeholder="t('companion.placeholder')"
            :disabled="sendForm.processing"
            maxlength="8000"
            rows="3"
          />
          <button
            type="button"
            class="portal-companion__upload"
            :aria-label="t('companion.attachImages')"
            :title="t('companion.attachImages')"
            :disabled="sendForm.processing"
            @click="openImagePicker"
          >
            <PortalIcon name="paperclip" />
            <span class="sr-only">{{ t('companion.attachImages') }}</span>
          </button>
          <input
            ref="imageInput"
            class="sr-only"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            multiple
            @change="selectImages"
          >
          <p
            v-if="sendForm.images.length"
            class="portal-copy portal-copy--small"
          >
            {{ t('companion.selectedImages', { count: sendForm.images.length }) }}
          </p>
          <label
            v-if="props.companion.canReinspectRecentImages && !sendForm.images.length"
            class="flex items-center gap-2 text-sm text-[var(--portal-color-ink-soft)]"
          >
            <input
              v-model="sendForm.reinspect_recent_images"
              type="checkbox"
            >
            <span>{{ t('companion.reinspectRecentImage') }}</span>
          </label>
          <div class="flex flex-wrap items-center justify-between gap-3">
            <p
              v-if="sendForm.errors.body || sendForm.errors.idempotency_key"
              class="portal-copy portal-copy--small text-[var(--portal-color-danger)]"
            >
              {{ t('common.error') }}
            </p>
            <p
              v-else-if="sendForm.processing"
              class="portal-copy portal-copy--small"
            >
              {{ t('companion.sending') }}
            </p>
            <span v-else />
            <button
              class="portal-button portal-button--primary"
              type="submit"
              :disabled="sendForm.processing || (!body.trim() && !sendForm.images.length)"
            >
              {{ sendForm.processing ? t('companion.sending') : t('companion.send') }}
            </button>
          </div>
        </form>
      </section>
    </section>
  </AppShell>
</template>
