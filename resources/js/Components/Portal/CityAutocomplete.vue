<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { useHttp } from '@inertiajs/vue3';
import { usePortalLocale } from '../../composables/usePortalLocale';
import TextAutocomplete from './TextAutocomplete.vue';

const props = defineProps<{ id: string; modelValue: string; country: string; citiesUrl: string; required?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const { locale, t } = usePortalLocale();
const cities = ref<string[]>([]);
const failed = ref(false);
const loading = ref(false);
const lookup = useHttp<{ country: string; query: string }, unknown>({ country: '', query: '' });
let generation = 0;
let timer: ReturnType<typeof setTimeout> | null = null;

watch(() => [props.country, props.modelValue, locale.value] as const, ([country, query]) => {
    const current = ++generation;
    if (timer) clearTimeout(timer);
    lookup.cancel();
    cities.value = [];
    failed.value = false;
    loading.value = false;
    if (!country.trim()) return;
    timer = setTimeout(() => {
        loading.value = true;
        lookup.country = country;
        lookup.query = query;
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
            onFinish: () => { if (current === generation) loading.value = false; },
        });
    }, 250);
}, { immediate: true });

onBeforeUnmount(() => { ++generation; if (timer) clearTimeout(timer); lookup.cancel(); });
</script>

<template>
  <TextAutocomplete
    :id="id"
    :model-value="modelValue"
    :suggestions="cities"
    :required="required"
    :filter-suggestions="false"
    :empty-text="t(loading ? 'profile.cityLoading' : failed ? 'profile.cityUnavailable' : 'profile.cityNoMatch')"
    @update:model-value="emit('update:modelValue', $event)"
  />
</template>
