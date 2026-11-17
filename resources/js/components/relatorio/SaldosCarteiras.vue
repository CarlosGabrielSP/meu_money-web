<script setup lang="ts">
import CarteiraIcone from '@/components/carteiras/CarteiraIcone.vue';
import { formatarMoeda } from '@/lib/dinheiro';
import type { SaldoCarteira } from '@/types';

defineProps<{
    carteiras: SaldoCarteira[];
    atual: number;
}>();

const emit = defineEmits<{
    escolher: [id: number];
}>();
</script>

<template>
    <!-- Every wallet with the current balance and the one estimated for the end of the month; the
         report's wallet is marked and tapping another switches the report (RN-186). -->
    <ul class="grid gap-1">
        <li v-for="carteira in carteiras" :key="carteira.id">
            <button
                type="button"
                class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="
                    carteira.id === atual
                        ? 'bg-accent ring-1 ring-foreground/20'
                        : 'hover:bg-accent'
                "
                :aria-current="carteira.id === atual ? 'true' : undefined"
                @click="carteira.id !== atual && emit('escolher', carteira.id)"
            >
                <CarteiraIcone :cor="carteira.cor" :icone="carteira.icone" />
                <span
                    class="min-w-0 flex-1 truncate"
                    :class="{ 'font-medium': carteira.id === atual }"
                    >{{ carteira.nome }}</span
                >
                <span class="grid shrink-0 text-right tabular-nums">
                    <span class="font-medium">{{
                        formatarMoeda(carteira.atual)
                    }}</span>
                    <span class="text-xs text-muted-foreground"
                        >previsto {{ formatarMoeda(carteira.estimado) }}</span
                    >
                </span>
            </button>
        </li>
    </ul>
</template>
