<script setup lang="ts">
import { formatarMoeda } from '@/lib/dinheiro';
import { formatarPercentual } from '@/lib/relatorio';
import type { DespesaTipo } from '@/types';

defineProps<{
    tipos: DespesaTipo[];
}>();

const rotulos: Record<DespesaTipo['tipo'], string> = {
    avista: 'À vista',
    parceladas: 'Parceladas',
    recorrentes: 'Recorrentes',
};

const tons: Record<DespesaTipo['tipo'], string> = {
    avista: 'bg-primary',
    parceladas: 'bg-primary/55',
    recorrentes: 'bg-primary/25',
};
</script>

<template>
    <!-- Expenses of the period by kind (RN-183): one split bar and the list with the values. -->
    <div class="grid gap-4">
        <div
            class="flex h-3 gap-0.5 overflow-hidden rounded-full bg-muted"
            aria-hidden="true"
        >
            <span
                v-for="tipo in tipos"
                v-show="tipo.valor > 0"
                :key="tipo.tipo"
                class="basis-0"
                :class="tons[tipo.tipo]"
                :style="{ flexGrow: tipo.valor }"
            />
        </div>
        <dl class="grid gap-2">
            <div
                v-for="tipo in tipos"
                :key="tipo.tipo"
                class="flex items-baseline justify-between gap-3"
            >
                <dt class="flex items-center gap-2">
                    <span
                        class="size-2.5 rounded-sm"
                        :class="tons[tipo.tipo]"
                        aria-hidden="true"
                    />
                    {{ rotulos[tipo.tipo] }}
                </dt>
                <dd class="tabular-nums">
                    <span class="font-medium">{{
                        formatarMoeda(tipo.valor)
                    }}</span>
                    <span class="ml-2 text-sm text-muted-foreground">{{
                        formatarPercentual(tipo.percentual)
                    }}</span>
                </dd>
            </div>
        </dl>
    </div>
</template>
