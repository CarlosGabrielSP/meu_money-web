<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import LancamentoController from '@/actions/App/Http/Controllers/LancamentoController';
import InputError from '@/components/InputError.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { executadoPorPadrao } from '@/lib/datas';
import { formatarCentavos, mascararValor } from '@/lib/dinheiro';
import {
    destroy as destroyAnexo,
    show as showAnexo,
} from '@/routes/lancamentos/anexo';
import type {
    CarteiraResumo,
    CategoriaOpcao,
    LancamentoEdicao,
    TipoLancamento,
} from '@/types';

const aberto = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    lancamentoId: number | null;
    edicao: LancamentoEdicao | null;
    carteiraId: number;
    dataPadrao: string;
    hoje: string;
    carteiras: CarteiraResumo[];
    categorias: CategoriaOpcao[];
}>();

type Campos = {
    tipo: TipoLancamento;
    descricao: string;
    valor: string;
    dataLancamento: string;
    categoria_id: number | null;
    carteira_id: number;
    observacao: string;
    executado: boolean | null;
    anexo: File | null;
};

const form = useForm<Campos>(camposIniciais());
const confirmandoExclusao = ref(false);
const page = usePage();
const anexoInput = ref<HTMLInputElement | null>(null);

const editando = computed(() => props.lancamentoId !== null);

const categoriasDoTipo = computed(() =>
    props.categorias.filter(
        (categoria) =>
            categoria.tipo === 'ambos' || categoria.tipo === form.tipo,
    ),
);

const executadoExibido = computed(
    () =>
        form.executado ??
        executadoPorPadrao(form.tipo, form.dataLancamento, props.hoje),
);

function camposIniciais(): Campos {
    const edicao = props.edicao;

    return {
        tipo: edicao?.tipo ?? 'despesa',
        descricao: edicao?.descricao ?? '',
        valor: edicao ? formatarCentavos(edicao.valor) : '',
        dataLancamento: edicao?.data ?? props.dataPadrao,
        categoria_id: edicao?.categoriaId ?? null,
        carteira_id: edicao?.carteiraId ?? props.carteiraId,
        observacao: edicao?.observacao ?? '',
        executado: edicao?.executado ?? null,
        anexo: null,
    };
}

watch(aberto, (estaAberto) => {
    if (estaAberto) {
        form.defaults(camposIniciais());
        form.reset();
        form.clearErrors();
    }
});

watch(
    () => form.tipo,
    () => {
        if (
            !categoriasDoTipo.value.some(
                (categoria) => categoria.id === form.categoria_id,
            )
        ) {
            form.categoria_id = null;
        }
    },
);

/**
 * "data" is reserved by useForm, so the date travels under its own name only on submit.
 */
function paraEnvio({ dataLancamento, ...campos }: Campos) {
    return { ...campos, data: dataLancamento };
}

const erros = computed(() => form.errors as Record<string, string | undefined>);

function escolherAnexo(event: Event): void {
    form.anexo = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function salvar(adicionarOutro: boolean): void {
    const opcoes = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            if (!adicionarOutro) {
                aberto.value = false;

                return;
            }

            const mantidos = {
                tipo: form.tipo,
                carteira_id: form.carteira_id,
                dataLancamento: form.dataLancamento,
            };

            form.defaults({ ...camposIniciais(), ...mantidos });
            form.reset();

            if (anexoInput.value) {
                anexoInput.value.value = '';
            }
        },
    };

    if (props.lancamentoId === null) {
        form.transform(paraEnvio).post(
            LancamentoController.store.url(),
            opcoes,
        );

        return;
    }

    form.transform((campos) => ({ ...paraEnvio(campos), _method: 'put' })).post(
        LancamentoController.update.url(props.lancamentoId),
        opcoes,
    );
}

function excluir(): void {
    if (props.lancamentoId === null) {
        return;
    }

    router.delete(LancamentoController.destroy.url(props.lancamentoId), {
        preserveScroll: true,
        onSuccess: () => {
            confirmandoExclusao.value = false;
            aberto.value = false;
        },
    });
}

function removerAnexo(): void {
    if (props.lancamentoId === null) {
        return;
    }

    router.delete(destroyAnexo.url(props.lancamentoId), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (aberto.value = false),
    });
}
</script>

<template>
    <div>
        <Sheet v-model:open="aberto">
            <SheetContent class="w-full overflow-y-auto sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>{{
                        editando ? 'Editar lançamento' : 'Novo lançamento'
                    }}</SheetTitle>
                    <SheetDescription class="sr-only">
                        Preencha os dados do lançamento.
                    </SheetDescription>
                </SheetHeader>

                <form
                    class="grid gap-4 px-4 pb-6"
                    @submit.prevent="salvar(false)"
                >
                    <fieldset class="flex gap-4">
                        <legend class="sr-only">Tipo</legend>
                        <label class="flex items-center gap-2">
                            <input
                                v-model="form.tipo"
                                type="radio"
                                value="despesa"
                            />
                            Despesa
                        </label>
                        <label class="flex items-center gap-2">
                            <input
                                v-model="form.tipo"
                                type="radio"
                                value="receita"
                            />
                            Receita
                        </label>
                    </fieldset>
                    <InputError :message="form.errors.tipo" />

                    <div class="grid gap-2">
                        <Label for="descricao">Descrição</Label>
                        <Input
                            id="descricao"
                            v-model="form.descricao"
                            maxlength="255"
                            required
                        />
                        <InputError :message="form.errors.descricao" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="valor">Valor (R$)</Label>
                            <Input
                                id="valor"
                                :model-value="form.valor"
                                inputmode="numeric"
                                placeholder="0,00"
                                required
                                @update:model-value="
                                    form.valor = mascararValor(String($event))
                                "
                            />
                            <InputError :message="form.errors.valor" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="data">Data</Label>
                            <Input
                                id="data"
                                v-model="form.dataLancamento"
                                type="date"
                                required
                            />
                            <InputError :message="erros.data" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="categoria_id">Categoria</Label>
                        <select
                            id="categoria_id"
                            v-model="form.categoria_id"
                            required
                            class="h-9 rounded-md border bg-transparent px-3 text-sm"
                        >
                            <option :value="null" disabled>Selecione</option>
                            <option
                                v-for="categoria in categoriasDoTipo"
                                :key="categoria.id"
                                :value="categoria.id"
                            >
                                {{ categoria.nome }}
                            </option>
                        </select>
                        <InputError :message="form.errors.categoria_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="carteira_id">Carteira</Label>
                        <select
                            id="carteira_id"
                            v-model="form.carteira_id"
                            class="h-9 rounded-md border bg-transparent px-3 text-sm"
                        >
                            <option
                                v-for="carteira in carteiras"
                                :key="carteira.id"
                                :value="carteira.id"
                            >
                                {{ carteira.nome }}
                            </option>
                        </select>
                        <InputError :message="form.errors.carteira_id" />
                    </div>

                    <label class="flex items-center gap-2">
                        <Checkbox
                            :model-value="executadoExibido"
                            @update:model-value="
                                form.executado = $event === true
                            "
                        />
                        Executado
                    </label>
                    <InputError :message="form.errors.executado" />

                    <div class="grid gap-2">
                        <Label for="observacao">Observação</Label>
                        <textarea
                            id="observacao"
                            v-model="form.observacao"
                            maxlength="1000"
                            rows="3"
                            class="rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                        <InputError :message="form.errors.observacao" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="anexo"
                            >Anexo (PDF, JPG, PNG ou WEBP, até 5 MB)</Label
                        >
                        <div
                            v-if="edicao?.anexoNome && lancamentoId !== null"
                            class="flex items-center justify-between gap-2 text-sm"
                        >
                            <a
                                :href="showAnexo.url(lancamentoId)"
                                class="truncate underline"
                                target="_blank"
                            >
                                {{ edicao.anexoNome }}
                            </a>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="removerAnexo"
                            >
                                Remover
                            </Button>
                        </div>
                        <input
                            id="anexo"
                            ref="anexoInput"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            class="text-sm"
                            @change="escolherAnexo"
                        />
                        <InputError :message="form.errors.anexo" />
                    </div>

                    <InputError :message="erros.lancamento" />

                    <div class="flex flex-wrap gap-2">
                        <Button type="submit" :disabled="form.processing">
                            Salvar
                        </Button>
                        <Button
                            v-if="!editando"
                            type="button"
                            variant="secondary"
                            :disabled="form.processing"
                            @click="salvar(true)"
                        >
                            Salvar e adicionar outro
                        </Button>
                        <Button
                            v-if="editando"
                            type="button"
                            variant="destructive"
                            class="ml-auto"
                            @click="confirmandoExclusao = true"
                        >
                            Excluir
                        </Button>
                    </div>
                </form>
            </SheetContent>
        </Sheet>

        <Dialog v-model:open="confirmandoExclusao">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Excluir lançamento?</DialogTitle>
                    <DialogDescription>
                        O lançamento e o anexo serão removidos.
                    </DialogDescription>
                </DialogHeader>
                <InputError :message="page.props.errors.lancamento" />
                <DialogFooter>
                    <Button variant="destructive" @click="excluir"
                        >Excluir</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
