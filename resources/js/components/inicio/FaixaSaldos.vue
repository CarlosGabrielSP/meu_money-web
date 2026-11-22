<script setup lang="ts">
import BotaoPrivacidade from '@/components/BotaoPrivacidade.vue';
import { formatarMoeda } from '@/lib/dinheiro';
import type { SaldosInicio, VisaoSaldos } from '@/types';

defineProps<{
    visivel: boolean;
    carteira: string;
    mes: string;
    /** Null on a card, which shows the invoice to pay instead (RN-223). */
    saldos: SaldosInicio | null;
    aPagar?: number | null;
    simples: boolean;
}>();

const visao = defineModel<VisaoSaldos>({ required: true });
</script>

<template>
    <!-- Compact strip that replaces the card while it is off the screen (RN-170). The zero-height
         sticky wrapper keeps it out of the flow; it counts as a fixed bar for the tour only when
         visible (data-tutorial-fixo with visibility). -->
    <div class="sticky top-0 z-20 h-0">
        <div
            class="absolute inset-x-0 top-0 flex items-center gap-3 bg-saldo px-4 py-2 text-sm text-saldo-foreground shadow-md transition-[opacity,translate] duration-200 motion-reduce:transition-none"
            :class="
                visivel
                    ? 'translate-y-0 opacity-100'
                    : 'invisible -translate-y-2 opacity-0'
            "
            :aria-hidden="!visivel"
            data-tutorial-fixo
        >
            <div
                class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-0.5"
            >
                <span class="min-w-0 truncate text-saldo-foreground/75">
                    {{ carteira }}, {{ mes }}
                </span>
                <span
                    v-if="saldos === null"
                    class="ml-auto shrink-0 tabular-nums"
                >
                    <span class="text-saldo-foreground/75">A pagar </span>
                    <strong class="font-semibold">{{
                        formatarMoeda(aPagar ?? 0)
                    }}</strong>
                </span>
                <template v-else>
                    <select
                        v-model="visao"
                        aria-label="Visão dos saldos"
                        class="min-h-8 max-w-full rounded-md border border-saldo-foreground/40 bg-saldo px-1 text-xs text-saldo-foreground"
                    >
                        <option value="acumulado">Acumulado</option>
                        <option value="mes">Resultado do mês</option>
                    </select>
                    <strong
                        v-if="visao === 'mes'"
                        class="ml-auto font-semibold tabular-nums"
                    >
                        {{ formatarMoeda(saldos.mes.estimado) }}
                    </strong>
                    <span v-else class="ml-auto shrink-0 tabular-nums">
                        <span class="text-saldo-foreground/75">Atual </span>
                        <strong class="font-semibold">{{
                            formatarMoeda(saldos.acumulado.atual)
                        }}</strong>
                    </span>
                    <span
                        v-if="!simples && visao === 'acumulado'"
                        class="shrink-0 tabular-nums"
                    >
                        <span class="text-saldo-foreground/75">Previsto </span>
                        <strong class="font-semibold">{{
                            formatarMoeda(saldos.acumulado.estimado)
                        }}</strong>
                    </span>
                </template>
            </div>
            <BotaoPrivacidade />
        </div>
    </div>
</template>
