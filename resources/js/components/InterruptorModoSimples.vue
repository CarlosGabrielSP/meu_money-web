<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { SwitchRoot, SwitchThumb } from 'reka-ui';
import { ref } from 'vue';
import { useModoSimples } from '@/composables/useModoSimples';
import { update } from '@/routes/appearance';

const modoSimples = useModoSimples();
const enviando = ref(false);

/**
 * Turns the simple mode on or off in the account (RN-157, CA-112.1).
 */
function alternar(ligado: boolean): void {
    router.patch(
        update().url,
        { modo_simples: ligado },
        {
            preserveScroll: true,
            onStart: () => (enviando.value = true),
            onFinish: () => (enviando.value = false),
        },
    );
}
</script>

<template>
    <label
        class="flex items-center justify-between gap-4 rounded-lg border p-3"
    >
        <span class="font-medium">Letras maiores e menos itens na tela</span>
        <SwitchRoot
            :model-value="modoSimples"
            :disabled="enviando"
            data-test="modo-simples"
            class="inline-flex h-6 w-11 shrink-0 items-center rounded-full border-2 border-transparent bg-input transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-60 data-[state=checked]:bg-primary simples:h-8 simples:w-14"
            @update:model-value="alternar"
        >
            <SwitchThumb
                class="block size-5 rounded-full bg-background shadow transition-transform data-[state=checked]:translate-x-5 simples:size-7 simples:data-[state=checked]:translate-x-6"
            />
        </SwitchRoot>
    </label>
</template>
