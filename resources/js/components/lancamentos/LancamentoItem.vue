<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Paperclip, Repeat, Users } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { diaEMes } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import { executado } from '@/routes/cotas';
import type { ItemLancamento } from '@/types';

const props = defineProps<{
    item: ItemLancamento;
}>();

const emit = defineEmits<{
    editar: [item: ItemLancamento];
}>();

function alternar(marcado: boolean): void {
    router.patch(
        executado.url(props.item.cotaId),
        { executado: marcado },
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <li
        class="flex items-center gap-3 px-3 py-2"
        :class="{ 'bg-destructive/5': item.vencido }"
    >
        <Checkbox
            :model-value="item.executado"
            :aria-label="`Executado: ${item.descricao}`"
            @update:model-value="alternar($event === true)"
        />
        <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-3 text-left"
            :disabled="item.edicao === null"
            @click="emit('editar', item)"
        >
            <span class="w-11 shrink-0 text-sm text-muted-foreground">
                {{ diaEMes(item.data) }}
            </span>
            <span class="min-w-0 flex-1">
                <span class="flex items-center gap-1.5">
                    <span class="truncate font-medium">{{
                        item.descricao
                    }}</span>
                    <span
                        v-if="item.parcela"
                        class="text-xs text-muted-foreground"
                    >
                        {{ item.parcela }}
                    </span>
                    <Repeat
                        v-if="item.recorrente"
                        class="size-3.5 shrink-0 text-muted-foreground"
                        aria-label="Recorrente"
                    />
                    <Users
                        v-if="item.compartilhado"
                        class="size-3.5 shrink-0 text-muted-foreground"
                        aria-label="Compartilhado"
                    />
                    <Paperclip
                        v-if="item.temAnexo"
                        class="size-3.5 shrink-0 text-muted-foreground"
                        aria-label="Com anexo"
                    />
                </span>
                <span
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    {{ item.categoria }}
                    <Badge v-if="item.vencido" variant="destructive"
                        >Vencido</Badge
                    >
                </span>
            </span>
            <span class="shrink-0 font-medium tabular-nums">
                {{ formatarMoeda(item.valor) }}
            </span>
        </button>
    </li>
</template>
