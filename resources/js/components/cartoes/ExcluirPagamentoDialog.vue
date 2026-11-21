<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { dataCompleta } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import { destroy as excluirPagamento } from '@/routes/faturas/pagamentos';
import type { PagamentoFatura } from '@/types';

/** The payment to delete; null closes the dialog. */
const pagamento = defineModel<PagamentoFatura | null>({ required: true });
const processando = ref(false);

/**
 * A payment is deleted, never edited (RN-222); the member uses the same confirmation (RN-281).
 */
function confirmarExclusao(): void {
    if (pagamento.value === null) {
        return;
    }

    router.delete(excluirPagamento.url(pagamento.value.id), {
        preserveScroll: true,
        onStart: () => (processando.value = true),
        onFinish: () => (processando.value = false),
        onSuccess: () => (pagamento.value = null),
    });
}
</script>

<template>
    <Dialog
        :open="pagamento !== null"
        @update:open="(aberto) => !aberto && (pagamento = null)"
    >
        <DialogContent v-if="pagamento">
            <DialogHeader>
                <DialogTitle>
                    Excluir pagamento de
                    {{ formatarMoeda(pagamento.valor) }} de
                    {{ dataCompleta(pagamento.data) }}?
                </DialogTitle>
            </DialogHeader>
            <DialogFooter>
                <Button
                    variant="destructive"
                    :disabled="processando"
                    @click="confirmarExclusao"
                >
                    Excluir pagamento
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
