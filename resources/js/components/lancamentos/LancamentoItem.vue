<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Paperclip, Repeat, Users } from '@lucide/vue';
import { computed, onBeforeUnmount } from 'vue';
import CategoriaIcone from '@/components/categorias/CategoriaIcone.vue';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { diaEMes } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import { executado } from '@/routes/cotas';
import type { ItemLancamento } from '@/types';

const props = defineProps<{
    item: ItemLancamento;
    selecionando?: boolean;
    selecionado?: boolean;
}>();

const emit = defineEmits<{
    editar: [item: ItemLancamento];
    selecionar: [item: ItemLancamento];
}>();

const selecionavel = computed(() => props.item.cotaId !== null);
let temporizador: ReturnType<typeof setTimeout> | null = null;
let pressionouLongo = false;

/**
 * Press and hold on mobile starts the selection (screens and flows, "Agrupar").
 */
function iniciarPressao(evento: PointerEvent): void {
    pressionouLongo = false;

    if (evento.pointerType !== 'touch' || !selecionavel.value) {
        return;
    }

    temporizador = setTimeout(() => {
        pressionouLongo = true;
        emit('selecionar', props.item);
    }, 500);
}

function cancelarPressao(): void {
    if (temporizador !== null) {
        clearTimeout(temporizador);
        temporizador = null;
    }
}

onBeforeUnmount(cancelarPressao);

function clicar(): void {
    if (pressionouLongo) {
        pressionouLongo = false;

        return;
    }

    if (props.selecionando) {
        if (selecionavel.value) {
            emit('selecionar', props.item);
        }

        return;
    }

    emit('editar', props.item);
}

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
        class="flex items-center gap-3 px-3 py-2.5"
        :class="{
            'bg-destructive/5': item.vencido && !selecionado,
            'bg-primary/10': selecionado,
        }"
    >
        <template v-if="selecionando">
            <span v-if="!selecionavel" class="size-4 shrink-0" />
            <Checkbox
                v-else
                class="rounded-full"
                :model-value="selecionado"
                :aria-label="`Selecionar: ${item.descricao}`"
                @update:model-value="emit('selecionar', item)"
            />
        </template>
        <span v-else-if="item.cotaId === null" class="size-4 shrink-0" />
        <Checkbox
            v-else
            :model-value="item.executado"
            :aria-label="`Executado: ${item.descricao}`"
            data-tutorial="executado"
            @update:model-value="alternar($event === true)"
        />
        <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-3 text-left"
            :disabled="
                selecionando
                    ? !selecionavel
                    : item.edicao === null && item.participacao === null
            "
            @click="clicar"
            @pointerdown="iniciarPressao"
            @pointerup="cancelarPressao"
            @pointerleave="cancelarPressao"
            @pointercancel="cancelarPressao"
            @contextmenu="selecionavel && $event.preventDefault()"
        >
            <CategoriaIcone :icone="item.categoriaIcone" />
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
                    <span class="tabular-nums">{{ diaEMes(item.data) }}</span>
                    {{ item.categoria }}
                    <span v-if="item.autor">· de {{ item.autor }}</span>
                    <Badge v-if="item.vencido" variant="destructive"
                        >Vencido</Badge
                    >
                </span>
            </span>
            <span class="shrink-0 text-right tabular-nums">
                <span class="block font-medium">{{
                    formatarMoeda(item.valor)
                }}</span>
                <span
                    v-if="item.compartilhado"
                    class="block text-xs text-muted-foreground"
                    >de {{ formatarMoeda(item.total) }}</span
                >
            </span>
        </button>
    </li>
</template>
