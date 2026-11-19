<script setup lang="ts">
import CarteiraIcone from '@/components/carteiras/CarteiraIcone.vue';
import { formatarMoeda } from '@/lib/dinheiro';
import type { SaldoCarteira } from '@/types';

withDefaults(
    defineProps<{
        carteiras: SaldoCarteira[];
        /** Cards with their current and estimated debt, outside the sum (RN-227, R9). */
        cartoes?: SaldoCarteira[];
        atual: number;
    }>(),
    { cartoes: () => [] },
);

const emit = defineEmits<{
    escolher: [id: number];
}>();
</script>

<template>
    <!-- Every wallet with the current balance and the one estimated for the end of the month; the
         report's wallet is marked and tapping another switches the report (RN-186). -->
    <div class="grid gap-3">
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
                    @click="
                        carteira.id !== atual && emit('escolher', carteira.id)
                    "
                >
                    <CarteiraIcone
                        :cor="carteira.cor"
                        :icone="carteira.icone"
                    />
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
                            >previsto
                            {{ formatarMoeda(carteira.estimado) }}</span
                        >
                    </span>
                </button>
            </li>
        </ul>
        <div v-if="cartoes.length > 0" class="grid gap-1">
            <h3 class="px-2 text-xs text-muted-foreground">Cartões</h3>
            <ul class="grid gap-1">
                <li v-for="cartao in cartoes" :key="cartao.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="
                            cartao.id === atual
                                ? 'bg-accent ring-1 ring-foreground/20'
                                : 'hover:bg-accent'
                        "
                        :aria-current="cartao.id === atual ? 'true' : undefined"
                        @click="
                            cartao.id !== atual && emit('escolher', cartao.id)
                        "
                    >
                        <CarteiraIcone
                            :cor="cartao.cor"
                            :icone="cartao.icone"
                        />
                        <span
                            class="min-w-0 flex-1 truncate"
                            :class="{ 'font-medium': cartao.id === atual }"
                            >{{ cartao.nome }}</span
                        >
                        <span
                            class="grid shrink-0 text-right text-sm tabular-nums"
                        >
                            <span
                                >Dívida atual
                                {{ formatarMoeda(cartao.atual) }}</span
                            >
                            <span class="text-xs text-muted-foreground"
                                >estimada
                                {{ formatarMoeda(cartao.estimado) }}</span
                            >
                        </span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
