<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
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
    contentHtml?: string | null;
    occurredAt: string;
    transportLabel: string | null;
    feedback: 'helpful' | 'not_helpful' | null;
    feedbackAvailable: boolean;
    attachmentCount: number;
    traceUrl: null;
    safeActions: string[];
};

type CompanionState = {
    messages: TimelineItem[];
    hasOlder: boolean;
    nextBeforeMessageId: number | null;
    state: 'ai_active' | 'human_handoff';
    stateLabel: string;
    mode: 'ai_active' | 'specialist_notified' | 'staff_active' | 'handoff_paused';
    pending: boolean;
    canReinspectRecentImages: boolean;
    openEscalation: { reasonLabel: string; openedAt: string } | null;
};

const props = defineProps<{
    portal: PortalShell;
    companion: CompanionState;
    urls: { send: string; feedback: string; retry: string; specialist: string; reset: string; history: string };
    notices: { retry: string | null; specialistRequested: string | null };
}>();

const { t, formatDateTime } = usePortalLocale();
const retryAccepted = computed(() => props.notices.retry === 'accepted' && props.companion.pending);
const body = ref('');
const olderLoading = ref(false);
const imageInput = ref<HTMLInputElement | null>(null);
const historyElement = ref<HTMLElement | null>(null);
const showNewMessages = ref(false);
const followNewest = ref(true);
const actionPendingId = ref<string | null>(null);
const feedbackPending = ref<Record<string, boolean>>({});
const feedbackSubmitted = ref<Record<string, 'helpful' | 'not_helpful'>>({});
const feedbackErrors = ref<Record<string, string>>({});
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

function handleComposerKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Enter' || event.shiftKey || event.isComposing) {
        return;
    }

    event.preventDefault();
    send();
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
    if (message.type !== 'message' || message.role !== 'ai' || !message.feedbackAvailable) {
        return;
    }

    const messageId = String(message.id);
    if (message.feedback !== null || feedbackSubmitted.value[messageId] !== undefined || feedbackPending.value[messageId]) {
        return;
    }

    feedbackPending.value[messageId] = true;
    delete feedbackErrors.value[messageId];
    let saved = false;
    router.post(props.urls.feedback.replace('__id__', messageId), { value }, {
        preserveScroll: true,
        onSuccess: () => {
            saved = true;
            feedbackSubmitted.value[messageId] = value;
        },
        onError: () => {
            feedbackErrors.value[messageId] = t('companion.feedbackError');
        },
        onFinish: () => {
            delete feedbackPending.value[messageId];
            if (!saved && !feedbackErrors.value[messageId]) {
                feedbackErrors.value[messageId] = t('companion.feedbackError');
            }
        },
    });
}

function feedbackFor(message: TimelineItem): 'helpful' | 'not_helpful' | null {
    return message.feedback ?? feedbackSubmitted.value[String(message.id)] ?? null;
}

function submitSafeAction(message: TimelineItem, action: 'retry_failed_turn' | 'request_human'): void {
    const actionId = `${message.id}:${action}`;
    if (actionPendingId.value !== null) {
        return;
    }

    actionPendingId.value = actionId;
    const url = action === 'retry_failed_turn'
        ? props.urls.retry.replace('__id__', String(message.id))
        : props.urls.specialist.replace('__id__', String(message.id));
    router.post(url, {}, {
        preserveScroll: true,
        onFinish: () => {
            actionPendingId.value = null;
        },
    });
}

function resetContext(): void {
    if (window.confirm(t('companion.resetConfirm'))) {
        router.post(props.urls.reset, {}, { preserveScroll: true });
    }
}

function formatDate(value: string): string {
    return formatDateTime(value);
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
    const wasFollowing = followNewest.value;
    await nextTick();
    if (wasFollowing && isNearBottom(historyElement.value)) {
        scrollToNewest();
        return;
    }

    followNewest.value = false;
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
              :content-html="message.contentHtml ?? undefined"
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
              v-if="message.type === 'message' && message.role === 'ai' && message.safeActions.length"
              class="mt-3 flex flex-wrap gap-2"
            >
              <button
                v-if="message.safeActions.includes('retry_failed_turn')"
                class="portal-button portal-button--primary"
                type="button"
                :disabled="actionPendingId !== null || props.companion.state !== 'ai_active'"
                @click="submitSafeAction(message, 'retry_failed_turn')"
              >
                {{ actionPendingId === `${message.id}:retry_failed_turn` ? t('companion.retrying') : t('companion.retry') }}
              </button>
              <button
                v-if="message.safeActions.includes('request_human') && ['ai_active', 'specialist_notified'].includes(props.companion.mode)"
                class="portal-button portal-button--secondary"
                type="button"
                :disabled="actionPendingId !== null"
                @click="submitSafeAction(message, 'request_human')"
              >
                {{ t('companion.requestSpecialist') }}
              </button>
            </div>
            <div
              v-if="message.type === 'message' && message.role === 'ai' && (message.feedbackAvailable || feedbackSubmitted[String(message.id)] || feedbackErrors[String(message.id)])"
              class="mt-3 flex flex-wrap gap-2"
            >
              <button
                v-if="feedbackFor(message) === null && !feedbackPending[String(message.id)]"
                class="portal-button portal-button--secondary"
                type="button"
                @click="submitFeedback(message, 'helpful')"
              >
                {{ t('companion.feedbackHelpful') }}
              </button>
              <button
                v-if="feedbackFor(message) === null && !feedbackPending[String(message.id)]"
                class="portal-button portal-button--secondary"
                type="button"
                @click="submitFeedback(message, 'not_helpful')"
              >
                {{ t('companion.feedbackNotHelpful') }}
              </button>
              <p
                v-if="feedbackPending[String(message.id)]"
                class="portal-notice portal-notice--success basis-full"
                role="status"
              >
                {{ t('companion.feedbackSaving') }}
              </p>
              <p
                v-else-if="feedbackFor(message) === 'helpful'"
                class="portal-notice portal-notice--success basis-full"
                role="status"
              >
                {{ t('companion.feedbackThanksHelpful') }}
              </p>
              <p
                v-else-if="feedbackFor(message) === 'not_helpful'"
                class="portal-notice portal-notice--success basis-full"
                role="status"
              >
                {{ t('companion.feedbackThanksNotHelpful') }}
              </p>
              <p
                v-if="feedbackErrors[String(message.id)]"
                class="portal-notice portal-notice--error basis-full"
                role="alert"
              >
                {{ feedbackErrors[String(message.id)] }}
              </p>
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
            v-if="props.companion.mode !== 'ai_active' || props.companion.openEscalation || retryAccepted || props.notices.retry === 'unavailable' || props.notices.specialistRequested === 'unavailable'"
            class="portal-panel"
            role="status"
          >
            <p
              v-if="props.companion.mode !== 'ai_active'"
              class="portal-copy"
            >
              {{ props.companion.mode === 'staff_active' ? t('companion.paused') : props.companion.mode === 'handoff_paused' ? t('companion.pausedUnconfirmed') : t('companion.stateSpecialistNotified') }}
            </p>
            <p
              v-if="props.companion.openEscalation"
              class="portal-copy portal-copy--small"
            >
              {{ props.companion.openEscalation.reasonLabel }}
            </p>
            <p
              v-if="retryAccepted"
              class="portal-copy portal-copy--small"
            >
              {{ t('companion.retryAccepted') }}
            </p>
            <p
              v-else-if="props.notices.retry === 'unavailable'"
              class="portal-copy portal-copy--small text-[var(--portal-color-danger)]"
            >
              {{ t('companion.retryUnavailable') }}
            </p>
            <p
              v-if="props.notices.specialistRequested === 'unavailable'"
              class="portal-copy portal-copy--small text-[var(--portal-color-danger)]"
            >
              {{ t('companion.specialistUnavailable') }}
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
            @keydown="handleComposerKeydown"
          />
          <div class="portal-companion__composer-toolbar">
            <div class="portal-companion__composer-meta">
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
            </div>
            <div class="portal-companion__composer-buttons">
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
        </form>
      </section>
    </section>
  </AppShell>
</template>
