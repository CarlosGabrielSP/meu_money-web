<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import CarteiraIcone from '@/components/carteiras/CarteiraIcone.vue';
import ExcluirPagamentoDialog from '@/components/cartoes/ExcluirPagamentoDialog.vue';
import PagarFaturaPainel from '@/components/cartoes/PagarFaturaPainel.vue';
import LancamentoItem from '@/components/lancamentos/LancamentoItem.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dataCompleta, diaEMes } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import { home } from '@/routes';
import type { FaturaVinculada, ItemLancamento, PagamentoFatura } from '@/types';

const props = defineProps<{
    fatura: FaturaVinculada;
    hoje: string;
    /** Simple mode: a plain item, without expanding (DEC-087). */
    simples?: boolean;
}>();

const emit = defineEmits<{
    editar: [item: ItemLancamento];
}>();

const expandido = ref(false);
const pagando = ref(false);
const excluindo = ref<PagamentoFatura | null>(null);

/**
 * The row of a member of the wallet only (RF-208): it expands instead of opening the card, which is not
 * delegated to them. A member of the card too gets the owner's row (RN-322, RN-334).
 */
const doMembro = computed(() => props.fatura.faturaDoMes);
/** "Pagar" only with something left to pay (RN-223, RN-281). */
const podePagar = computed(() => (doMembro.value?.aPagar ?? 0) > 0);
/** The whole simple-mode row opens "Pagar"; with nothing to pay a tap does nothing (RN-310). */
const linhaPaga = computed(
    () => props.simples === true && doMembro.value !== null && podePagar.value,
);

const situacoes = {
    a_pagar: 'A pagar',
    parcial: 'Parcial',
    quitada: 'Quitada',
};

function tocar(): void {
    if (linhaPaga.value) {
        pagando.value = true;
    } else if (!props.simples) {
        expandido.value = !expandido.value;
    }
}
</script>

<template>
    <!-- One invoice of a card paid by this wallet, due in the month (RN-219): no checkbox; the
         name opens the card screen in the invoice month, the arrow expands its items. For a member of
         the delegated wallet only the name expands too, with the payments from the wallet and "Pagar"
         (RN-281, RN-311); with the card also delegated the row is the owner's (RN-334). -->
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
            <component
                :is="
                    doMembro === null
                        ? Link
                        : linhaPaga || !simples
                          ? 'button'
                          : 'div'
                "
                :type="
                    doMembro !== null && (linhaPaga || !simples)
                        ? 'button'
                        : undefined
                "
                :href="
                    doMembro === null
                        ? home({
                              query: {
                                  carteira: fatura.carteiraId,
                                  mes: fatura.mes,
                              },
                          })
                        : undefined
                "
                :aria-expanded="
                    doMembro !== null && !simples ? expandido : undefined
                "
                class="flex min-h-10 min-w-0 flex-1 items-center gap-3 text-left"
                @click="doMembro !== null && tocar()"
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
            </component>
            <Button
                v-if="doMembro !== null && !simples && podePagar"
                size="sm"
                variant="outline"
                class="shrink-0"
                @click="pagando = true"
            >
                Pagar
            </Button>
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
            <template
                v-if="doMembro !== null && doMembro.pagamentosLista.length > 0"
            >
                <li class="px-3 pt-2.5 pb-1 text-xs text-muted-foreground">
                    Pagamentos
                </li>
                <li
                    v-for="pagamento in doMembro.pagamentosLista"
                    :key="pagamento.id"
                    class="flex items-center gap-3 px-3 py-2 text-sm"
                >
                    <span class="min-w-0 flex-1">
                        {{ diaEMes(pagamento.data) }} Pagamento
                    </span>
                    <span class="shrink-0 text-right tabular-nums">
                        {{ formatarMoeda(pagamento.valor) }}
                        <span
                            v-if="pagamento.pagoPor"
                            class="text-muted-foreground"
                        >
                            · pago por {{ pagamento.pagoPor }}
                        </span>
                    </span>
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
            </template>
        </ul>

        <template v-if="doMembro !== null">
            <PagarFaturaPainel
                v-model:open="pagando"
                :cartao-id="fatura.carteiraId"
                :cartao-nome="fatura.nome"
                :fatura="doMembro"
                :hoje="hoje"
            />
            <ExcluirPagamentoDialog v-model="excluindo" />
        </template>
    </li>
</template>
