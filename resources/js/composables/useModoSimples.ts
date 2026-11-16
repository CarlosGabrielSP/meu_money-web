import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';

/**
 * Simple mode of the account, shared with every page (RN-157, CA-112.2).
 */
export function useModoSimples(): ComputedRef<boolean> {
    const page = usePage();

    return computed(() => page.props.modoSimples === true);
}
