<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { useHttp } from '@inertiajs/vue3';
import { usePortalLocale } from '../../composables/usePortalLocale';
import TextAutocomplete from './TextAutocomplete.vue';

const props = defineProps<{ id: string; modelValue: string; country: string; citiesUrl: string; required?: boolean }>();
const emit = defineEmits<{
    'update:modelValue': [value: string];
    'lookup-loading': [loading: boolean];
}>();
const { locale, t } = usePortalLocale();
const cities = ref<string[]>([]);
const failed = ref(false);
const loading = ref(false);
const lookup = useHttp<{ country: string; query: string }, unknown>({ country: '', query: '' });
let generation = 0;
let activated = false;
let timer: ReturnType<typeof setTimeout> | null = null;

function setLoading(value: boolean): void {
    if (loading.value === value) return;
    loading.value = value;
    emit('lookup-loading', value);
}

function refresh(): void {
    const current = ++generation;
    if (timer) clearTimeout(timer);
    lookup.cancel();
    cities.value = [];
    failed.value = false;
    if (!activated || !props.country.trim()) {
        setLoading(false);
        return;
    }
    setLoading(true);
    timer = setTimeout(() => {
        timer = null;
        lookup.country = props.country;
        lookup.query = props.modelValue;
        const fail = (): void => { if (current === generation) failed.value = true; };
        lookup.post(props.citiesUrl, {
            onSuccess: (result) => {
                if (current !== generation) return;
                if (!result || typeof result !== 'object' || !('cities' in result) || !Array.isArray(result.cities)) {
                    fail();
                    return;
                }
                cities.value = result.cities.filter((value): value is string => typeof value === 'string').slice(0, 25);
            },
            onError: fail,
            onHttpException: fail,
            onNetworkError: fail,
            onFinish: () => { if (current === generation) setLoading(false); },
        });
    }, 250);
}

function activate(): void {
    if (activated) return;
    activated = true;
    refresh();
}

watch(() => [props.country, props.modelValue, locale.value], refresh, { immediate: true });

onBeforeUnmount(() => { ++generation; if (timer) clearTimeout(timer); lookup.cancel(); setLoading(false); });
</script>

<template>
  <TextAutocomplete
    :id="id"
    :model-value="modelValue"
    :suggestions="cities"
    :required="required"
    :filter-suggestions="false"
    :empty-text="t(loading ? 'profile.cityLoading' : failed ? 'profile.cityUnavailable' : 'profile.cityNoMatch')"
    @focus="activate"
    @update:model-value="emit('update:modelValue', $event)"
  />
</template>
