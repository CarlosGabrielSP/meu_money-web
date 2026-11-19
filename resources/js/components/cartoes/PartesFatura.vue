<script setup lang="ts">
import { formatarMoeda } from '@/lib/dinheiro';
import type { ParteDaFatura } from '@/types';

defineProps<{
    partes: ParteDaFatura[];
}>();

/**
 * Who settled a contact's part: "por B", "por você" or both (nota 07).
 */
function quemQuitou(parte: ParteDaFatura): string {
    const quem = [
        ...(parte.quitadoPeloContato > 0 ? [parte.nome] : []),
        ...(parte.quitadoPeloAutor > 0 ? ['você'] : []),
    ];

    return quem.length === 0 ? '' : ` · por ${quem.join(' e ')}`;
}

function quitado(parte: ParteDaFatura): number {
    return parte.quitadoPeloContato + parte.quitadoPeloAutor;
}
</script>

<template>
    <!-- "Partes" block of the author's invoice (RN-202, RN-244, RN-257), hidden without shared purchases. -->
    <section v-if="partes.length > 0" class="grid gap-2" aria-label="Partes">
        <h2 class="text-sm font-medium">Partes</h2>
        <ul class="divide-y overflow-hidden rounded-2xl border bg-card">
            <li
                v-for="parte in partes"
                :key="parte.userId"
                class="flex items-start gap-3 px-3 py-2 text-sm"
            >
                <span class="min-w-0 flex-1 truncate font-medium">{{
                    parte.nome
                }}</span>
                <span class="font-medium tabular-nums">{{
                    formatarMoeda(parte.parte)
                }}</span>
                <span
                    class="grid w-40 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
                >
                    <span v-if="!parte.voce || quitado(parte) > 0">
                        quitado {{ formatarMoeda(quitado(parte))
                        }}{{ parte.voce ? '' : quemQuitou(parte) }}
                    </span>
                    <span v-if="parte.aberto > 0 || quitado(parte) === 0">
                        em aberto {{ formatarMoeda(parte.aberto) }}
                    </span>
                </span>
            </li>
        </ul>
    </section>
</template>
