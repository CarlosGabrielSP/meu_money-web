<script setup lang="ts">
import { formatarMoeda } from '@/lib/dinheiro';
import type { RateioRelatorio, TotaisRateio } from '@/types';

defineProps<{
    rateio: RateioRelatorio;
}>();

const lados: {
    chave: keyof RateioRelatorio;
    titulo: string;
    explicacao: string;
}[] = [
    {
        chave: 'dividiu',
        titulo: 'Você dividiu',
        explicacao: 'Partes dos seus contatos nos seus lançamentos',
    },
    {
        chave: 'recebeu',
        titulo: 'Você recebeu',
        explicacao: 'Sua parte em lançamentos dos seus contatos',
    },
];

function quantidade(totais: TotaisRateio): string {
    return totais.lancamentos === 1
        ? '1 lançamento'
        : `${totais.lancamentos} lançamentos`;
}
</script>

<template>
    <!-- Sharing in the period (RN-187). -->
    <div class="grid gap-4 sm:grid-cols-2">
        <div
            v-for="lado in lados"
            :key="lado.chave"
            class="grid content-start gap-2"
        >
            <h3 class="grid">
                <span class="font-medium">{{ lado.titulo }}</span>
                <span class="text-sm text-muted-foreground">{{
                    lado.explicacao
                }}</span>
            </h3>
            <p class="text-sm text-muted-foreground">
                {{ quantidade(rateio[lado.chave]) }}
            </p>
            <dl class="grid gap-1 tabular-nums">
                <div class="flex justify-between gap-3">
                    <dt>Despesas</dt>
                    <dd class="font-medium text-red-600 dark:text-red-400">
                        {{ formatarMoeda(rateio[lado.chave].despesas) }}
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt>Receitas</dt>
                    <dd
                        class="font-medium text-emerald-600 dark:text-emerald-400"
                    >
                        {{ formatarMoeda(rateio[lado.chave].receitas) }}
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</template>
