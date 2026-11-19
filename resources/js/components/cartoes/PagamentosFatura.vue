<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
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
import type { FaturaDoMes, PagamentoFatura } from '@/types';

defineProps<{
    fatura: FaturaDoMes;
}>();

const excluindo = ref<PagamentoFatura | null>(null);
const processando = ref(false);

/**
 * A payment is deleted, never edited (RN-222).
 */
function confirmarExclusao(): void {
    if (excluindo.value === null) {
        return;
    }

    router.delete(excluirPagamento.url(excluindo.value.id), {
        preserveScroll: true,
        onStart: () => (processando.value = true),
        onFinish: () => (processando.value = false),
        onSuccess: () => (excluindo.value = null),
    });
}
</script>

<template>
    <!-- Payments of the invoice, hidden when there is none (nota 07). -->
    <div v-if="fatura.pagamentosLista.length > 0">
        <section class="grid gap-2" aria-label="Pagamentos">
            <h2 class="text-sm font-medium">Pagamentos</h2>
            <ul class="divide-y overflow-hidden rounded-2xl border bg-card">
                <li
                    v-for="pagamento in fatura.pagamentosLista"
                    :key="pagamento.id"
                    class="flex items-center gap-3 px-3 py-2"
                >
                    <span class="min-w-0 flex-1 text-sm">
                        {{ dataCompleta(pagamento.data) }} ·
                        {{
                            pagamento.origem === null
                                ? 'fora do app'
                                : `de ${pagamento.origem}`
                        }}
                    </span>
                    <span class="font-medium tabular-nums">{{
                        formatarMoeda(pagamento.valor)
                    }}</span>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-8"
                        :aria-label="`Excluir pagamento de ${dataCompleta(pagamento.data)}`"
                        @click="excluindo = pagamento"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </li>
            </ul>
        </section>

        <Dialog
            :open="excluindo !== null"
            @update:open="(aberto) => !aberto && (excluindo = null)"
        >
            <DialogContent v-if="excluindo">
                <DialogHeader>
                    <DialogTitle>
                        Excluir pagamento de
                        {{ formatarMoeda(excluindo.valor) }} de
                        {{ dataCompleta(excluindo.data) }}?
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
    </div>
</template>
