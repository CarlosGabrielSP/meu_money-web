<script setup lang="ts">
import CategoriaIcone from '@/components/categorias/CategoriaIcone.vue';
import { formatarMoeda } from '@/lib/dinheiro';
import type { GastoDoMes } from '@/types';

const props = defineProps<{
    gastos: GastoDoMes[];
    totalDespesas: number;
}>();

function proporcao(valor: number): string {
    return `${props.totalDespesas > 0 ? (valor / props.totalDespesas) * 100 : 0}%`;
}
</script>

<template>
    <!-- The month's three largest expense categories, with a bar against the month's expenses
         (RN-174). Hidden when the month has none. -->
    <section
        v-if="gastos.length > 0"
        class="grid gap-3 rounded-2xl border bg-card p-4"
        aria-labelledby="gastos-do-mes"
    >
        <h2 id="gastos-do-mes" class="font-semibold">Gastos do mês</h2>
        <ul class="grid gap-3">
            <li
                v-for="gasto in gastos"
                :key="gasto.nome"
                class="flex items-center gap-3"
            >
                <CategoriaIcone :icone="gasto.icone" class="size-8" />
                <span class="grid min-w-0 flex-1 gap-1">
                    <span class="flex items-baseline justify-between gap-2">
                        <span class="truncate text-sm">{{ gasto.nome }}</span>
                        <span class="text-sm font-medium tabular-nums">{{
                            formatarMoeda(gasto.valor)
                        }}</span>
                    </span>
                    <span class="h-1.5 overflow-hidden rounded-full bg-muted">
                        <span
                            class="block h-full rounded-full bg-primary"
                            :style="{ width: proporcao(gasto.valor) }"
                        />
                    </span>
                </span>
            </li>
        </ul>
    </section>
</template>
