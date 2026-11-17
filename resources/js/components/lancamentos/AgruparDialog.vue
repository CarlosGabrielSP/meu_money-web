<script setup lang="ts">
import CampoSelecao from '@/components/CampoSelecao.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { agrupar } from '@/routes/grupos';
import type { EscopoSerie, GrupoOpcao, TipoLancamento } from '@/types';

const aberto = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    cotas: number[];
    tipo: TipoLancamento | null;
    temParcela: boolean;
    grupos: GrupoOpcao[];
}>();

const emit = defineEmits<{
    concluido: [];
}>();

const modo = ref<'novo' | 'existente'>('novo');
const form = useForm({
    nome: '',
    grupo_id: null as number | null,
    escopo: 'esta' as EscopoSerie,
});

/**
 * Only groups of the same type are offered (CA-090.3); the list already holds the wallet's groups.
 */
const compativeis = computed(() =>
    props.grupos.filter((grupo) => grupo.tipo === props.tipo),
);

watch(aberto, (estaAberto) => {
    if (estaAberto) {
        modo.value = 'novo';
        form.reset();
        form.clearErrors();
        form.grupo_id = compativeis.value[0]?.id ?? null;
    }
});

/**
 * Errors of the selection, including per item ones (cotas.0, cotas.1...).
 */
const erroDaSelecao = computed(
    () =>
        Object.entries(form.errors).find(([campo]) =>
            campo.startsWith('cotas'),
        )?.[1],
);

const opcoesEscopo: { valor: EscopoSerie; titulo: string }[] = [
    { valor: 'esta', titulo: 'Só esta parcela' },
    { valor: 'proximas', titulo: 'Esta e as próximas' },
    { valor: 'todas', titulo: 'Todas as parcelas' },
];

function confirmar(): void {
    form.transform((dados) => ({
        cotas: props.cotas,
        ...(modo.value === 'novo'
            ? { nome: dados.nome }
            : { grupo_id: dados.grupo_id }),
        escopo: props.temParcela ? dados.escopo : null,
    })).post(agrupar.url(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            aberto.value = false;
            emit('concluido');
        },
    });
}
</script>

<template>
    <Dialog v-model:open="aberto">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Agrupar {{ cotas.length }} itens</DialogTitle>
                <DialogDescription>
                    O grupo só organiza a lista. Os saldos não mudam.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="confirmar">
                <fieldset class="grid gap-2">
                    <legend class="sr-only">Grupo</legend>
                    <label class="flex items-center gap-2">
                        <input v-model="modo" type="radio" value="novo" />
                        Novo grupo
                    </label>
                    <label
                        class="flex items-center gap-2"
                        :class="{ 'opacity-50': compativeis.length === 0 }"
                    >
                        <input
                            v-model="modo"
                            type="radio"
                            value="existente"
                            :disabled="compativeis.length === 0"
                        />
                        Grupo existente
                    </label>
                </fieldset>

                <div v-if="modo === 'novo'" class="grid gap-2">
                    <Label for="nome_grupo">Nome</Label>
                    <Input
                        id="nome_grupo"
                        v-model="form.nome"
                        maxlength="100"
                        placeholder="Ex.: Fatura Nubank"
                    />
                    <InputError :message="form.errors.nome" />
                </div>
                <div v-else class="grid gap-2">
                    <Label for="grupo_existente">Grupo</Label>
                    <CampoSelecao
                        id="grupo_existente"
                        v-model="form.grupo_id"
                        :opcoes="
                            compativeis.map((grupo) => ({
                                valor: grupo.id,
                                rotulo: grupo.nome,
                            }))
                        "
                    />
                    <InputError :message="form.errors.grupo_id" />
                </div>

                <fieldset v-if="temParcela" class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Nas parcelas selecionadas, agrupar
                    </legend>
                    <label
                        v-for="opcao in opcoesEscopo"
                        :key="opcao.valor"
                        class="flex items-center gap-2"
                    >
                        <input
                            v-model="form.escopo"
                            type="radio"
                            :value="opcao.valor"
                        />
                        {{ opcao.titulo }}
                    </label>
                    <p class="text-xs text-muted-foreground">
                        Nas opções em massa, entram as parcelas desta carteira,
                        e as futuras também entram quando nascem nela.
                    </p>
                </fieldset>

                <InputError :message="erroDaSelecao" />
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Agrupar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
