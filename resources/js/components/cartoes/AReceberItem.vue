<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import { computed } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { diaEMes } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import { recebido as rotaRecebido } from '@/routes/a-receber';
import type { ItemAReceber } from '@/types';

const props = defineProps<{
    item: ItemAReceber;
    /** Simple mode: bigger row with the "Recebido" label (RN-265). */
    simples?: boolean;
    /** Delegated wallet until stage 16.3: shown without action (DEC-099). */
    somenteLeitura?: boolean;
}>();

const todoRecebido = computed(() => props.item.recebido >= props.item.valor);
const parcial = computed(() => props.item.recebido > 0 && !todoRecebido.value);
/** Unmarking undoes only manual receipts, so an item received only automatically stays checked (RN-242, RN-265). */
const travado = computed(
    () =>
        props.somenteLeitura === true ||
        (todoRecebido.value && !props.item.manual),
);

function alternar(marcado: boolean): void {
    router.patch(
        rotaRecebido.url(props.item.id),
        { recebido: marcado },
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <!-- "A receber de B" (RN-216 to RN-218, RN-243): not editable, only the checkbox changes. -->
    <li
        class="flex items-center gap-3 px-3"
        :class="simples ? 'py-3' : 'py-2.5'"
    >
        <label
            v-if="simples"
            class="flex min-h-12 shrink-0 cursor-pointer items-center gap-2 rounded-md border px-3 font-medium"
        >
            <Checkbox
                :model-value="todoRecebido"
                :disabled="travado"
                :aria-label="`Recebido: A receber de ${item.nome}`"
                @update:model-value="alternar($event === true)"
            />
            Recebido
        </label>
        <Checkbox
            v-else
            :model-value="todoRecebido"
            :disabled="travado"
            :aria-label="`Recebido: A receber de ${item.nome}`"
            @update:model-value="alternar($event === true)"
        />
        <span
            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted"
        >
            <Users class="size-4 text-muted-foreground" aria-hidden="true" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate font-medium"
                >A receber de {{ item.nome }}</span
            >
            <span class="block text-xs text-muted-foreground tabular-nums">{{
                diaEMes(item.data)
            }}</span>
        </span>
        <span class="shrink-0 text-right tabular-nums">
            <span class="block font-medium">{{
                formatarMoeda(item.valor)
            }}</span>
            <span v-if="parcial" class="block text-xs text-muted-foreground"
                >recebido {{ formatarMoeda(item.recebido) }} de
                {{ formatarMoeda(item.valor) }}</span
            >
        </span>
    </li>
</template>
