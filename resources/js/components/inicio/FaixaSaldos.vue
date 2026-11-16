<script setup lang="ts">
import { formatarMoeda } from '@/lib/dinheiro';
import type { SaldosInicio } from '@/types';

defineProps<{
    visivel: boolean;
    carteira: string;
    mes: string;
    saldos: SaldosInicio;
    simples: boolean;
}>();
</script>

<template>
    <!-- Compact strip that replaces the card while it is off the screen (RN-170). The zero-height
         sticky wrapper keeps it out of the flow; it counts as a fixed bar for the tour only when
         visible (data-tutorial-fixo with visibility). -->
    <div class="sticky top-0 z-20 h-0">
        <div
            class="absolute inset-x-0 top-0 flex items-center gap-x-4 gap-y-0.5 bg-saldo px-4 py-2 text-sm text-saldo-foreground shadow-md transition-[opacity,translate] duration-200 motion-reduce:transition-none"
            :class="
                visivel
                    ? 'translate-y-0 opacity-100'
                    : 'invisible -translate-y-2 opacity-0'
            "
            :aria-hidden="!visivel"
            data-tutorial-fixo
        >
            <span class="min-w-0 truncate text-saldo-foreground/75">
                {{ carteira }}, {{ mes }}
            </span>
            <span class="ml-auto shrink-0 tabular-nums">
                <span class="text-saldo-foreground/75">Atual </span>
                <strong class="font-semibold">{{
                    formatarMoeda(saldos.acumulado.atual)
                }}</strong>
            </span>
            <span v-if="!simples" class="shrink-0 tabular-nums">
                <span class="text-saldo-foreground/75">Previsto </span>
                <strong class="font-semibold">{{
                    formatarMoeda(saldos.acumulado.estimado)
                }}</strong>
            </span>
        </div>
    </div>
</template>
