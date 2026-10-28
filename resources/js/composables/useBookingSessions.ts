import { computed, ref, type Ref } from 'vue';

export function useBookingSessions(selectedStart: Ref<string | null>) {
    const queuedStarts = ref<string[]>([]);
    const startsAtList = computed(() => [...new Set([
        ...queuedStarts.value,
        ...(selectedStart.value === null ? [] : [selectedStart.value]),
    ])]);
    const canAdd = computed(() => selectedStart.value !== null
        && !queuedStarts.value.includes(selectedStart.value)
        && queuedStarts.value.length < 19);
    let intentKey: string | null = null;
    let lastRequest: string | null = null;

    function add(): void {
        if (!canAdd.value || selectedStart.value === null) {
            return;
        }
        queuedStarts.value.push(selectedStart.value);
        selectedStart.value = null;
    }

    function remove(start: string): void {
        queuedStarts.value = queuedStarts.value.filter((item) => item !== start);
        if (selectedStart.value === start) {
            selectedStart.value = null;
        }
    }

    function keyFor(request: unknown): string {
        const serialized = JSON.stringify(request);
        if (intentKey === null || serialized !== lastRequest) {
            intentKey = window.crypto.randomUUID();
            lastRequest = serialized;
        }

        return intentKey;
    }

    return { queuedStarts, startsAtList, canAdd, add, remove, keyFor };
}
