<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { ref } from 'vue';
import CarteiraIcone from '@/components/carteiras/CarteiraIcone.vue';
import LancamentoItem from '@/components/lancamentos/LancamentoItem.vue';
import { Badge } from '@/components/ui/badge';
import { diaEMes } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import { home } from '@/routes';
import type { FaturaVinculada, ItemLancamento } from '@/types';

defineProps<{
    fatura: FaturaVinculada;
    /** Simple mode: a plain item, without expanding (DEC-087). */
    simples?: boolean;
}>();

const emit = defineEmits<{
    editar: [item: ItemLancamento];
}>();

const expandido = ref(false);

const situacoes = {
    a_pagar: 'A pagar',
    parcial: 'Parcial',
    quitada: 'Quitada',
};
</script>

<template>
    <!-- One invoice of a card paid by this wallet, due in the month (RN-219): no checkbox; the
         name opens the card screen in the invoice month, the arrow expands its items. -->
    <li>
        <div
            class="flex items-center gap-3 px-3"
            :class="simples ? 'py-3' : 'py-2.5'"
        >
            <button
                v-if="!simples"
                type="button"
                class="shrink-0"
                :aria-expanded="expandido"
                :aria-label="`Mostrar compras da fatura ${fatura.nome}`"
                @click="expandido = !expandido"
            >
                <ChevronRight
                    class="size-4 transition-transform"
                    :class="{ 'rotate-90': expandido }"
                />
            </button>
            <Link
                :href="
                    home({
                        query: { carteira: fatura.carteiraId, mes: fatura.mes },
                    })
                "
                class="flex min-h-10 min-w-0 flex-1 items-center gap-3"
            >
                <CarteiraIcone :cor="fatura.cor" :icone="fatura.icone" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{
                        fatura.nome
                    }}</span>
                    <span class="block text-xs text-muted-foreground"
                        >vence {{ diaEMes(fatura.vencimento) }}</span
                    >
                </span>
                <span class="shrink-0 text-right">
                    <span class="block font-medium tabular-nums">{{
                        formatarMoeda(fatura.valor)
                    }}</span>
                    <Badge
                        :variant="
                            fatura.situacao === 'quitada'
                                ? 'secondary'
                                : 'outline'
                        "
                        >{{ situacoes[fatura.situacao] }}</Badge
                    >
                </span>
            </Link>
        </div>
        <ul
            v-if="expandido && !simples"
            class="divide-y border-t bg-muted/30 pl-4"
        >
            <LancamentoItem
                v-for="item in fatura.itens"
                :key="item.chave"
                :item="item"
                @editar="emit('editar', $event)"
            />
        </ul>
    </li>
</template>
