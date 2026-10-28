<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { usePortalLocale } from '../../composables/usePortalLocale';

const props = defineProps<{ url: string; materials: { id: number; filename: string; downloadUrl: string }[] }>();
const emit = defineEmits<{ uploading: [value: boolean] }>();
const { t } = usePortalLocale();
type SelectedFile = { file: File; key: string; uploaded: boolean; error: string | null };
const selected = ref<SelectedFile[]>([]);
const selectionError = ref('');
const fileInput = ref<HTMLInputElement | null>(null);
const form = useForm<{ file: File | null; upload_key: string }>({ file: null, upload_key: '' });
const uploading = ref(false);
const activeName = ref('');
const maxFiles = 5;
const remainingFiles = computed(() => Math.max(0, maxFiles - props.materials.length));
let stopped = false;
let stopAfterCurrent = false;
onBeforeUnmount(() => {
    stopped = true;
    form.cancel();
});

function select(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    selectionError.value = '';
    if (files.length === 0) {
        return;
    }
    if (files.length > 5) {
        selectionError.value = t('medical.filesTooMany');
        return;
    }
    if (files.length > remainingFiles.value) {
        selectionError.value = remainingFiles.value === 1
            ? t('medical.oneFileRemaining')
            : t('medical.filesRemaining', { count: remainingFiles.value });
        return;
    }
    if (files.some((file) => file.size > 20 * 1024 * 1024)) {
        selectionError.value = t('medical.fileTooLarge');
        return;
    }
    if (files.some((file) => !['image/jpeg', 'image/png', 'application/pdf'].includes(file.type))) {
        selectionError.value = t('medical.unsupportedFileType');
        return;
    }
    selected.value = files.map((file) => ({ file, key: crypto.randomUUID(), uploaded: false, error: null }));
}

function openFilePicker(): void {
    if (!uploading.value && remainingFiles.value > 0) {
        fileInput.value?.click();
    }
}

function upload(): void {
    if (uploading.value || selectionError.value) return;
    uploading.value = true;
    stopAfterCurrent = false;
    emit('uploading', true);
    send(0);
}

function finishUpload(): void {
    uploading.value = false;
    emit('uploading', false);
    activeName.value = '';
    form.reset();
}

function send(index: number): void {
    if (stopped) return;
    const item = selected.value[index];
    if (!item) {
        finishUpload();
        return;
    }
    if (item.uploaded) {
        send(index + 1);
        return;
    }
    item.error = null;
    activeName.value = item.file.name;
    form.file = item.file;
    form.upload_key = item.key;
    form.post(props.url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { item.uploaded = true; },
        onError: (errors) => {
            item.error = errors.upload_key
                ? t('medical.uploadConflict')
                : errors.file_count
                    ? t('medical.filesLimitReached')
                : errors.file
                    ? t('medical.uploadValidationFailed')
                    : t('medical.uploadFailed');
        },
        onNetworkError: () => {
            item.error = t('medical.uploadNetworkError');
            stopAfterCurrent = true;
            return false;
        },
        onHttpException: (response) => {
            item.error = response.status === 401 || response.status === 419
                ? t('medical.uploadSessionExpired')
                : response.status === 403
                    ? t('medical.uploadForbidden')
                    : response.status === 404
                        ? t('medical.uploadPatientUnavailable')
                        : response.status === 413
                            ? t('medical.uploadRequestTooLarge')
                        : t('medical.uploadFailed');
            stopAfterCurrent = true;
            return false;
        },
        onFinish: () => {
            if (!item.uploaded && !item.error) item.error = t('medical.uploadFailed');
            if (stopAfterCurrent) {
                finishUpload();
            } else {
                send(index + 1);
            }
        },
    });
}
</script>

<template>
  <section class="portal-stack min-w-0">
    <label
      for="medical-files"
      class="portal-label"
    >{{ t('medical.files') }}</label>
    <p class="portal-copy">
      {{ t('medical.filesLimit') }}
    </p>
    <p
      v-if="remainingFiles === 0"
      class="portal-copy"
      role="status"
    >
      {{ t('medical.filesLimitReached') }}
    </p>
    <input
      id="medical-files"
      ref="fileInput"
      type="file"
      multiple
      accept="image/jpeg,image/png,application/pdf"
      :disabled="uploading || remainingFiles === 0"
      aria-hidden="true"
      class="medical-attachments__input"
      tabindex="-1"
      @change="select"
    >
    <button
      type="button"
      class="portal-button portal-button--secondary self-start"
      :disabled="uploading || remainingFiles === 0"
      @click="openFilePicker"
    >
      {{ t('medical.chooseFiles') }}
    </button>
    <p
      v-if="selectionError"
      class="portal-error"
      role="alert"
    >
      {{ selectionError }}
    </p>
    <ul
      v-if="selected.length"
      class="portal-stack"
    >
      <li
        v-for="item in selected"
        :key="item.key"
        class="portal-copy break-words"
      >
        {{ item.file.name }} · {{ t(item.uploaded ? 'medical.uploaded' : 'medical.pendingUpload') }}
        <p
          v-if="item.error"
          class="portal-error"
          role="alert"
        >
          {{ item.error }}
        </p>
      </li>
    </ul>
    <p
      v-if="uploading"
      class="portal-copy break-words"
      role="status"
    >
      {{ activeName }} · {{ form.progress?.percentage ?? 0 }}%
    </p>
    <button
      v-if="selected.some((item) => !item.uploaded)"
      type="button"
      :disabled="uploading || Boolean(selectionError)"
      class="portal-button portal-button--primary self-start"
      @click="upload"
    >
      {{ t('medical.upload') }}
    </button>
    <ul
      v-if="materials.length"
      class="medical-attachments__list"
    >
      <li
        v-for="material in materials"
        :key="material.id"
        class="medical-attachments__item"
      >
        <span class="medical-attachments__filename">{{ material.filename }}</span>
        <a
          :href="material.downloadUrl"
          :download="material.filename"
          class="portal-button portal-button--secondary"
          :aria-label="t('medical.downloadFile', { name: material.filename })"
        >
          {{ t('medical.download') }}
        </a>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.medical-attachments__input {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    clip-path: inset(50%);
    white-space: nowrap;
}

.medical-attachments__input:focus-visible + .portal-button {
    outline: 2px solid var(--portal-color-ink);
    outline-offset: 3px;
}

.medical-attachments__list {
    display: grid;
    min-width: 0;
    gap: var(--portal-space-3);
    margin: 0;
    padding: 0;
    list-style: none;
}

.medical-attachments__item {
    display: flex;
    min-width: 0;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--portal-space-3);
    border: 1px solid var(--portal-color-border);
    border-radius: var(--portal-radius-md);
    background: var(--portal-color-surface);
    padding: var(--portal-space-3);
}

.medical-attachments__filename {
    min-width: 0;
    overflow-wrap: anywhere;
    color: var(--portal-color-ink);
    font-weight: 650;
}

@media (max-width: 40rem) {
    .medical-attachments__item > .portal-button {
        width: 100%;
    }
}
</style>
