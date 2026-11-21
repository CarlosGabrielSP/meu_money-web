<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ExcluirPagamentoDialog from '@/components/cartoes/ExcluirPagamentoDialog.vue';
import { Button } from '@/components/ui/button';
import { dataCompleta } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import type { FaturaDoMes, PagamentoFatura } from '@/types';

defineProps<{
    fatura: FaturaDoMes;
}>();

const excluindo = ref<PagamentoFatura | null>(null);
</script>

<template>
    <!-- Payments of the invoice, hidden when there is none (nota 07). A member's payment names who
         paid (RN-306). -->
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
                        <template v-if="pagamento.pagoPor">
                            · pago por {{ pagamento.pagoPor }}
                        </template>
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

        <ExcluirPagamentoDialog v-model="excluindo" />
    </div>
</template>
