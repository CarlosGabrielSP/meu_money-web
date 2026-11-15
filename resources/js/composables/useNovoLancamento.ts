import { ref } from 'vue';

const pedidos = ref(0);

/**
 * Lets the menu ask the main screen to open the new entry form (RN-096).
 */
export function useNovoLancamento() {
    return {
        pedidos,
        solicitar: (): void => {
            pedidos.value++;
        },
    };
}
