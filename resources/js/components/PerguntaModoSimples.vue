<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { primeiroAcesso } from '@/routes';

const props = defineProps<{
    perguntar: boolean;
}>();

const aberto = ref(props.perguntar);
const enviando = ref(false);

/**
 * Answer of the first access; it ends the first access (RN-155, RN-156). Closing without an answer
 * keeps the question for the next visit (CA-111.7).
 */
function responder(modoSimples: boolean): void {
    router.post(
        primeiroAcesso.url(),
        { modo_simples: modoSimples },
        {
            preserveScroll: true,
            onStart: () => (enviando.value = true),
            onFinish: () => (enviando.value = false),
            onSuccess: () => (aberto.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="aberto">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Quer usar o modo simples?</DialogTitle>
                <DialogDescription>
                    Letras maiores e menos itens na tela.
                </DialogDescription>
            </DialogHeader>

            <div
                class="grid gap-2 rounded-lg border bg-muted/40 p-4 text-lg"
                aria-hidden="true"
            >
                <div class="flex justify-between">
                    <span>Saldo atual</span>
                    <span class="font-semibold">R$ 7.000,00</span>
                </div>
                <div
                    class="flex items-center gap-3 rounded-md border bg-card p-3"
                >
                    <span class="rounded-md border px-3 py-2 font-medium">
                        ✓ Pago
                    </span>
                    <span>
                        Mercado
                        <span class="block font-semibold">R$ 200,00</span>
                    </span>
                </div>
            </div>

            <p class="text-sm text-muted-foreground">
                Você pode mudar depois em Configurações &gt; Aparência.
            </p>

            <!-- Order of note 07: [Sim] [Não, agora não]. -->
            <DialogFooter class="flex-col gap-2 sm:justify-start">
                <Button
                    :disabled="enviando"
                    data-test="modo-simples-sim"
                    @click="responder(true)"
                >
                    Sim
                </Button>
                <Button
                    variant="outline"
                    :disabled="enviando"
                    data-test="modo-simples-nao"
                    @click="responder(false)"
                >
                    Não, agora não
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
