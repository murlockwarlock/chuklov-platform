<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import PortalIcon from '../../Components/Portal/PortalIcon.vue';
import SafeRichText from '../../Components/Portal/SafeRichText.vue';
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
const historyElement = ref<HTMLElement | null>(null);
const showNewMessages = ref(false);
const followNewest = ref(true);
const sendForm = useForm<{ body: string; idempotency_key: string; images: File[]; reinspect_recent_images: boolean }>({
    body: '',
    idempotency_key: '',
    images: [],
    reinspect_recent_images: false,
});
let poller: number | undefined;

function isNearBottom(element: HTMLElement | null): boolean {
    if (element === null) {
        return true;
    }

    return element.scrollHeight - element.scrollTop - element.clientHeight <= 96;
}

function scrollToNewest(behavior: 'auto' | 'smooth' = 'auto'): void {
    const element = historyElement.value;
    if (element === null) {
        return;
    }

    followNewest.value = true;
    element.scrollTo({ top: element.scrollHeight, behavior });
    showNewMessages.value = false;
}

function handleHistoryScroll(): void {
    const nearBottom = isNearBottom(historyElement.value);
    followNewest.value = nearBottom;
    if (nearBottom) {
        showNewMessages.value = false;
    }
}

function prepareForIncomingMessages(): void {
    followNewest.value = isNearBottom(historyElement.value);
}

function messageSignature(): string {
    return `${props.companion.messages.map((message) => `${message.type}:${message.id}:${message.content}`).join('|')}|${props.companion.pending ? 'pending' : 'idle'}`;
}

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
    prepareForIncomingMessages();
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
    followNewest.value = false;
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
    historyElement.value?.addEventListener('scroll', handleHistoryScroll, { passive: true });
    nextTick(() => scrollToNewest());
    poller = window.setInterval(() => {
        if (props.companion.pending) {
            prepareForIncomingMessages();
            router.reload({ only: ['companion'] });
        }
    }, 4000);
});

onUnmounted(() => {
    historyElement.value?.removeEventListener('scroll', handleHistoryScroll);
    if (poller !== undefined) {
        window.clearInterval(poller);
    }
});

watch(messageSignature, async () => {
    await nextTick();
    if (followNewest.value) {
        scrollToNewest();
        return;
    }

    showNewMessages.value = true;
});
</script>

<template>
  <AppShell
    :title="t('companion.title')"
    :portal="props.portal"
    active="companion"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose portal-companion-page">
      <header class="portal-page-heading portal-companion-page__heading">
        <h1 class="portal-heading portal-heading--section">
          {{ t('companion.title') }}
        </h1>
      </header>

      <section
        class="portal-companion"
        aria-live="polite"
      >
        <div
          ref="historyElement"
          class="portal-companion__history"
          data-testid="companion-history"
        >
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
            <SafeRichText
              v-if="message.role === 'ai' || message.role === 'staff'"
              :content="message.content"
            />
            <p
              v-else
              class="m-0 text-[var(--portal-color-ink)]"
            >
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
            class="portal-companion__typing"
            role="status"
            aria-live="polite"
          >
            <span class="sr-only">{{ t('companion.typingAccessible') }}</span>
            <span aria-hidden="true">{{ t('companion.typing') }}</span>
            <span
              class="portal-companion__typing-dots"
              aria-hidden="true"
            >
              <i />
              <i />
              <i />
            </span>
          </div>
          <button
            v-if="showNewMessages"
            class="portal-companion__new-messages"
            type="button"
            data-testid="companion-new-messages"
            @click="scrollToNewest('smooth')"
          >
            {{ t('companion.newMessages') }}
          </button>
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
            rows="2"
          />
          <div class="portal-companion__composer-toolbar">
            <div class="portal-companion__composer-tools">
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
                class="portal-copy portal-copy--small portal-companion__selection"
              >
                {{ t('companion.selectedImages', { count: sendForm.images.length }) }}
              </p>
              <label
                v-if="props.companion.canReinspectRecentImages && !sendForm.images.length"
                class="portal-companion__reinspect"
              >
                <input
                  v-model="sendForm.reinspect_recent_images"
                  type="checkbox"
                >
                <span>{{ t('companion.reinspectRecentImage') }}</span>
              </label>
            </div>
            <div class="portal-companion__composer-actions">
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
              <div class="portal-companion__composer-buttons">
                <button
                  class="portal-companion__reset"
                  type="button"
                  :aria-label="t('companion.reset')"
                  :title="t('companion.reset')"
                  @click="resetContext"
                >
                  <PortalIcon name="refresh" />
                  <span class="sr-only">{{ t('companion.reset') }}</span>
                </button>
                <button
                  class="portal-button portal-button--primary"
                  type="submit"
                  :disabled="sendForm.processing || (!body.trim() && !sendForm.images.length)"
                >
                  {{ sendForm.processing ? t('companion.sending') : t('companion.send') }}
                </button>
              </div>
            </div>
          </div>
        </form>
      </section>
    </section>
  </AppShell>
</template>
