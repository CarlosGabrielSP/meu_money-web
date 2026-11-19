<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ChevronRight, EllipsisVertical, Layers } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import LancamentoItem from '@/components/lancamentos/LancamentoItem.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatarMoeda } from '@/lib/dinheiro';
import { destroy, executado, update } from '@/routes/grupos';
import type { GrupoLinha, ItemLancamento } from '@/types';

const props = defineProps<{
    grupo: GrupoLinha;
    selecionando: boolean;
    selecionados: Set<number>;
}>();

const emit = defineEmits<{
    editar: [item: ItemLancamento];
    selecionar: [item: ItemLancamento];
}>();

const expandido = ref(false);
const renomeando = ref(false);
const excluindo = ref(false);
const processando = ref(false);
const renomear = useForm({ nome: props.grupo.nome });

const opcoes = { preserveScroll: true, preserveState: true };

/**
 * Marks or unmarks every visible share of the group in the month (RN-128).
 */
function executar(): void {
    router.patch(
        executado.url(props.grupo.id),
        {
            executado: props.grupo.status !== 'executado',
            cotas: props.grupo.itens.map((item) => item.cotaId),
        },
        {
            ...opcoes,
            onError: (erros) => {
                toast.error(
                    Object.values(erros)[0] ??
                        'Não foi possível atualizar o grupo.',
                );
            },
        },
    );
}

function abrirRenomear(): void {
    renomear.nome = props.grupo.nome;
    renomear.clearErrors();
    renomeando.value = true;
}

function confirmarRenomear(): void {
    renomear.patch(update.url(props.grupo.id), {
        ...opcoes,
        onSuccess: () => (renomeando.value = false),
    });
}

function excluir(): void {
    router.delete(destroy.url(props.grupo.id), {
        ...opcoes,
        onStart: () => (processando.value = true),
        onFinish: () => (processando.value = false),
        onSuccess: () => (excluindo.value = false),
    });
}
</script>

<template>
    <li>
        <div class="flex items-center gap-3 px-3 py-2">
            <!-- A group in a card has no status (RN-211). -->
            <span
                v-if="selecionando || grupo.status === null"
                class="size-4 shrink-0"
            />
            <Checkbox
                v-else
                :model-value="
                    grupo.status === 'parcial'
                        ? 'indeterminate'
                        : grupo.status === 'executado'
                "
                :aria-label="`Executar grupo: ${grupo.nome}`"
                data-tutorial="executado"
                @update:model-value="executar"
            />
            <button
                type="button"
                class="flex min-w-0 flex-1 items-center gap-2 text-left"
                :aria-expanded="expandido"
                @click="expandido = !expandido"
            >
                <ChevronRight
                    class="size-4 shrink-0 transition-transform"
                    :class="{ 'rotate-90': expandido }"
                />
                <span class="min-w-0 flex-1">
                    <span class="flex items-center gap-1.5">
                        <span class="truncate font-medium">{{
                            grupo.nome
                        }}</span>
                        <Layers
                            class="size-3.5 shrink-0 text-muted-foreground"
                            aria-label="Grupo"
                        />
                        <span class="text-xs text-muted-foreground">
                            {{ grupo.quantidade }}
                            {{ grupo.quantidade === 1 ? 'item' : 'itens' }}
                        </span>
                    </span>
                    <span
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <template v-if="grupo.status !== null">{{
                            {
                                executado: 'Executado',
                                parcial: 'Parcial',
                                pendente: 'Pendente',
                            }[grupo.status]
                        }}</template>
                        <Badge v-if="grupo.filtrado" variant="secondary"
                            >Filtrado</Badge
                        >
                    </span>
                </span>
                <span class="shrink-0 font-medium tabular-nums">
                    {{ formatarMoeda(grupo.valor) }}
                </span>
            </button>
            <DropdownMenu>
                <DropdownMenuTrigger :as-child="true">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-8"
                        :aria-label="`Ações do grupo ${grupo.nome}`"
                    >
                        <EllipsisVertical />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem @select="abrirRenomear">
                        Renomear
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        variant="destructive"
                        @select="excluindo = true"
                    >
                        Excluir grupo
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <ul v-if="expandido" class="divide-y border-t bg-muted/30 pl-4">
            <LancamentoItem
                v-for="item in grupo.itens"
                :key="item.chave"
                :item="item"
                :selecionando="selecionando"
                :selecionado="
                    item.cotaId !== null && selecionados.has(item.cotaId)
                "
                @editar="emit('editar', $event)"
                @selecionar="emit('selecionar', $event)"
            />
        </ul>

        <Dialog v-model:open="renomeando">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Renomear grupo</DialogTitle>
                    <DialogDescription>
                        O nome muda em todos os meses.
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-2" @submit.prevent="confirmarRenomear">
                    <Label :for="`nome-grupo-${grupo.id}`">Nome</Label>
                    <Input
                        :id="`nome-grupo-${grupo.id}`"
                        v-model="renomear.nome"
                        maxlength="100"
                        required
                    />
                    <InputError :message="renomear.errors.nome" />
                    <DialogFooter>
                        <Button type="submit" :disabled="renomear.processing">
                            Salvar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="excluindo">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Excluir grupo "{{ grupo.nome }}"?</DialogTitle>
                    <DialogDescription>
                        Só o agrupamento é desfeito, em todos os meses. Os
                        lançamentos continuam e os saldos não mudam.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="destructive"
                        :disabled="processando"
                        @click="excluir"
                    >
                        Excluir grupo
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </li>
</template>
