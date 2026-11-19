<script setup lang="ts">
import CarteiraIcone from '@/components/carteiras/CarteiraIcone.vue';
import { formatarMoeda } from '@/lib/dinheiro';
import type { CarteiraResumo } from '@/types';

defineProps<{
    carteiras: CarteiraResumo[];
    atual: number;
    saldos: Record<string, number>;
}>();

const emit = defineEmits<{
    escolher: [id: number];
}>();
</script>

<template>
    <!-- One card per wallet with its current balance; tapping one shows that wallet (RN-171). -->
    <nav
        aria-label="Carteiras"
        class="flex snap-x [scrollbar-width:none] gap-2 overflow-x-auto pb-1"
        data-tutorial="carteira"
    >
        <button
            v-for="carteira in carteiras"
            :key="carteira.id"
            type="button"
            class="flex min-w-40 shrink-0 snap-start items-center gap-3 rounded-2xl border bg-card px-3 py-2.5 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :class="
                carteira.id === atual
                    ? 'border-foreground/70 shadow-sm'
                    : 'text-muted-foreground hover:bg-accent'
            "
            :aria-current="carteira.id === atual ? 'true' : undefined"
            @click="carteira.id !== atual && emit('escolher', carteira.id)"
        >
            <CarteiraIcone :cor="carteira.cor" :icone="carteira.icone" />
            <span class="grid min-w-0">
                <span
                    class="truncate text-sm"
                    :class="{
                        'font-medium text-foreground': carteira.id === atual,
                    }"
                    >{{ carteira.nome }}</span
                >
                <!-- A card shows the to pay of the month invoice (RN-225). -->
                <span class="text-sm font-semibold text-foreground tabular-nums"
                    ><span
                        v-if="carteira.tipo === 'cartao'"
                        class="font-normal text-muted-foreground"
                        >a pagar </span
                    >{{ formatarMoeda(saldos[carteira.id] ?? 0) }}</span
                >
            </span>
        </button>
    </nav>
</template>
