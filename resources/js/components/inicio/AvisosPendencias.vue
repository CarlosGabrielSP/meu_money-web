<script setup lang="ts">
import { CalendarClock, ChevronRight, TriangleAlert } from '@lucide/vue';
import { formatarMoeda } from '@/lib/dinheiro';
import type { DestaquesInicio } from '@/types';

defineProps<{
    vencidas: DestaquesInicio['vencidas'];
    proximas: DestaquesInicio['proximas'];
}>();

const emit = defineEmits<{
    abrir: [painel: 'vencidas' | 'proximas'];
}>();

function plural(quantidade: number, singular: string, varias: string): string {
    return `${quantidade} ${quantidade === 1 ? singular : varias}`;
}
</script>

<template>
    <!-- Overdue shares and expenses due in the next 7 days; each opens its panel and hides at zero
         (RN-172, RN-173). -->
    <div
        v-if="vencidas.quantidade > 0 || proximas.quantidade > 0"
        class="grid gap-2"
    >
        <button
            v-if="vencidas.quantidade > 0"
            type="button"
            class="flex items-center gap-3 rounded-2xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-left hover:bg-destructive/10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            data-test="aviso-vencidas"
            @click="emit('abrir', 'vencidas')"
        >
            <TriangleAlert class="size-5 shrink-0 text-destructive" />
            <span class="grid min-w-0 flex-1">
                <span class="font-medium">{{
                    plural(
                        vencidas.quantidade,
                        'conta vencida',
                        'contas vencidas',
                    )
                }}</span>
                <span class="text-sm text-muted-foreground tabular-nums">
                    <template v-if="vencidas.despesas > 0"
                        >{{ formatarMoeda(vencidas.despesas) }} a
                        pagar</template
                    ><template
                        v-if="vencidas.despesas > 0 && vencidas.receitas > 0"
                        >, </template
                    ><template v-if="vencidas.receitas > 0"
                        >{{ formatarMoeda(vencidas.receitas) }} a
                        receber</template
                    >
                </span>
            </span>
            <ChevronRight class="size-4 shrink-0 text-muted-foreground" />
        </button>
        <button
            v-if="proximas.quantidade > 0"
            type="button"
            class="flex items-center gap-3 rounded-2xl border bg-card px-4 py-3 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            data-test="aviso-proximas"
            @click="emit('abrir', 'proximas')"
        >
            <CalendarClock class="size-5 shrink-0 text-muted-foreground" />
            <span class="grid min-w-0 flex-1">
                <span class="font-medium">{{
                    plural(
                        proximas.quantidade,
                        'conta vence em 7 dias',
                        'contas vencem em 7 dias',
                    )
                }}</span>
                <span class="text-sm text-muted-foreground tabular-nums"
                    >{{ formatarMoeda(proximas.total) }} a pagar</span
                >
            </span>
            <ChevronRight class="size-4 shrink-0 text-muted-foreground" />
        </button>
    </div>
</template>
