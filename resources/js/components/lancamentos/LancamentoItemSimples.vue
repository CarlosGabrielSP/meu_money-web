<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import CategoriaIcone from '@/components/categorias/CategoriaIcone.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { diaEMes } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import { executado } from '@/routes/cotas';
import type { ItemLancamento, TipoLancamento } from '@/types';

const props = defineProps<{
    item: ItemLancamento;
    tipo: TipoLancamento;
}>();

const emit = defineEmits<{
    editar: [item: ItemLancamento];
}>();

const rotuloExecutado = computed(() =>
    props.tipo === 'receita' ? 'Recebido' : 'Pago',
);

/**
 * Text indicators of the simple mode instead of icons (RN-159).
 */
const indicadores = computed(() =>
    [
        props.item.recorrente
            ? 'Repete'
            : props.item.parcela && `Parcela ${props.item.parcela}`,
        props.item.compartilhado && 'Dividido',
        props.item.autor && `de ${props.item.autor}`,
        props.item.temAnexo && 'Anexo',
    ].filter((indicador): indicador is string => Boolean(indicador)),
);

function alternar(marcado: boolean): void {
    if (props.item.cotaId === null) {
        return;
    }

    router.patch(
        executado.url(props.item.cotaId),
        { executado: marcado },
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <li
        class="flex items-center gap-3 px-3 py-3"
        :class="{ 'bg-destructive/5': item.vencido }"
    >
        <label
            v-if="!item.cartao"
            class="flex min-h-12 shrink-0 cursor-pointer items-center gap-2 rounded-md border px-3 font-medium"
            :class="{ invisible: item.cotaId === null }"
            data-tutorial="executado"
        >
            <Checkbox
                :model-value="item.executado"
                :disabled="item.cotaId === null"
                :aria-label="`${rotuloExecutado}: ${item.descricao}`"
                @update:model-value="alternar($event === true)"
            />
            {{ rotuloExecutado }}
        </label>
        <button
            type="button"
            class="flex min-h-12 min-w-0 flex-1 items-center gap-3 text-left"
            :disabled="item.edicao === null && item.participacao === null"
            @click="emit('editar', item)"
        >
            <CategoriaIcone :icone="item.categoriaIcone" />
            <span class="flex min-w-0 flex-1 flex-col items-start">
                <span class="flex w-full min-w-0 gap-2">
                    <span class="shrink-0 text-muted-foreground">
                        {{ diaEMes(item.data) }}
                    </span>
                    <span class="truncate font-medium">{{
                        item.descricao
                    }}</span>
                </span>
                <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-semibold tabular-nums">
                        {{ formatarMoeda(item.valor) }}
                    </span>
                    <span
                        v-for="indicador in indicadores"
                        :key="indicador"
                        class="text-sm text-muted-foreground"
                    >
                        {{ indicador }}
                    </span>
                    <span
                        v-if="item.vencido"
                        class="text-sm font-semibold text-destructive"
                    >
                        Vencido
                    </span>
                </span>
            </span>
        </button>
    </li>
</template>
