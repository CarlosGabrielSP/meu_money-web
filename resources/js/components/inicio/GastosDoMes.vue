<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { ref, useId } from 'vue';
import CategoriaIcone from '@/components/categorias/CategoriaIcone.vue';
import { formatarMoeda } from '@/lib/dinheiro';
import { relatorio } from '@/routes';
import type { GastoDoMes } from '@/types';

const props = defineProps<{
    gastos: GastoDoMes[];
    totalDespesas: number;
    carteiraId: number;
    /** "Gastos da fatura" on a card (RN-224, nota 07). */
    titulo?: string;
    /** A delegated wallet has no "Ver relatório" (RN-279, CA-203.5). */
    semRelatorio?: boolean;
    recolhivelNoMobile?: boolean;
}>();

const expandido = ref(false);
const idDoConteudo = useId();

function proporcao(valor: number): string {
    return `${props.totalDespesas > 0 ? (valor / props.totalDespesas) * 100 : 0}%`;
}
</script>

<template>
    <!-- The month's three largest expense categories, with a bar against the month's expenses
         (RN-174). Hidden when the month has none. "Ver relatório" opens this month of the wallet
         in the report. -->
    <section
        v-if="gastos.length > 0"
        class="grid gap-3 rounded-2xl border bg-card p-4"
        aria-labelledby="gastos-do-mes"
    >
        <h2 id="gastos-do-mes" class="font-semibold">
            <button
                v-if="recolhivelNoMobile"
                type="button"
                class="flex min-h-6 w-full items-center justify-between gap-2 text-left md:hidden"
                :aria-expanded="expandido"
                :aria-controls="idDoConteudo"
                @click="expandido = !expandido"
            >
                {{ titulo ?? 'Gastos do mês' }}
                <ChevronDown
                    aria-hidden="true"
                    class="size-4 shrink-0"
                    :class="{ 'rotate-180': expandido }"
                />
            </button>
            <span :class="{ 'hidden md:inline': recolhivelNoMobile }">
                {{ titulo ?? 'Gastos do mês' }}
            </span>
        </h2>
        <div
            :id="idDoConteudo"
            class="grid gap-3"
            :class="{ 'max-md:hidden': recolhivelNoMobile && !expandido }"
        >
            <ul class="grid gap-3">
                <li
                    v-for="gasto in gastos"
                    :key="gasto.nome"
                    class="flex items-center gap-3"
                >
                    <CategoriaIcone :icone="gasto.icone" class="size-8" />
                    <span class="grid min-w-0 flex-1 gap-1">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-sm">{{
                                gasto.nome
                            }}</span>
                            <span class="text-sm font-medium tabular-nums">{{
                                formatarMoeda(gasto.valor)
                            }}</span>
                        </span>
                        <span
                            class="h-1.5 overflow-hidden rounded-full bg-muted"
                        >
                            <span
                                class="block h-full rounded-full bg-primary"
                                :style="{ width: proporcao(gasto.valor) }"
                            />
                        </span>
                    </span>
                </li>
            </ul>
            <Link
                v-if="!semRelatorio"
                :href="
                    relatorio({
                        query: { carteira: carteiraId, periodo: 'mes' },
                    })
                "
                class="justify-self-end text-sm font-medium underline underline-offset-4 hover:text-muted-foreground"
            >
                Ver relatório
            </Link>
        </div>
    </section>
</template>
