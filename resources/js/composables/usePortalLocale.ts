import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { portalText } from '../locales/portal';
import type { PortalLocale, PortalShell } from '../types/portal';

type PortalPageProps = {
    portal: PortalShell;
};

export function usePortalLocale() {
    const page = usePage<PortalPageProps>();
    const locale = computed<PortalLocale>(() => page.props.portal?.locale === 'en' ? 'en' : 'ru');

    return {
        locale,
        t: (key: string, values: Record<string, string | number> = {}): string =>
            portalText(locale.value, key, values),
    };
}
