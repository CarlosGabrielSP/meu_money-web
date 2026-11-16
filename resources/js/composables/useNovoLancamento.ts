import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { home } from '@/routes';

const pedidos = ref(0);

/**
 * Lets the menu ask the main screen to open the new entry form (RN-096).
 */
export function useNovoLancamento() {
    const page = usePage();

    const solicitar = (): void => {
        pedidos.value++;
    };

    return {
        pedidos,
        solicitar,
        /**
         * The add button is always available (RN-096): on the main screen it opens the form, elsewhere it goes there first.
         */
        adicionar: (): void => {
            if (page.component === 'Inicio') {
                solicitar();

                return;
            }

            router.visit(home.url({ query: { novo: '1' } }));
        },
    };
}
