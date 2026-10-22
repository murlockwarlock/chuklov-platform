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
      type="file"
      multiple
      accept="image/jpeg,image/png,application/pdf"
      :disabled="uploading || remainingFiles === 0"
      class="portal-input min-w-0 max-w-full"
      @change="select"
    >
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
      class="portal-button portal-button--secondary"
      @click="upload"
    >
      {{ t('medical.upload') }}
    </button>
    <ul
      v-if="materials.length"
      class="portal-stack"
    >
      <li
        v-for="material in materials"
        :key="material.id"
        class="min-w-0"
      >
        <a
          :href="material.downloadUrl"
          class="portal-link break-words"
        >{{ material.filename }}</a>
      </li>
    </ul>
  </section>
</template>
