<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import EscopoSerieDialog from '@/components/lancamentos/EscopoSerieDialog.vue';
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
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatarMoeda } from '@/lib/dinheiro';
import { destroy, update } from '@/routes/cotas';
import { show as showAnexo } from '@/routes/lancamentos/anexo';
import type {
    CarteiraResumo,
    CategoriaOpcao,
    EscopoSerie,
    ItemLancamento,
    TipoLancamento,
} from '@/types';

const aberto = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    item: ItemLancamento | null;
    tipo: TipoLancamento;
    carteiras: CarteiraResumo[];
    categorias: CategoriaOpcao[];
}>();

const page = usePage();
const confirmandoSaida = ref(false);
const escolhendoEscopo = ref(false);

const form = useForm({
    carteira_id: 0,
    categoria_id: 0,
    executado: false,
});

const categoriasDoTipo = computed(() =>
    props.categorias.filter(
        (categoria) =>
            categoria.tipo === 'ambos' || categoria.tipo === props.tipo,
    ),
);

watch(aberto, (estaAberto) => {
    const participacao = props.item?.participacao;

    if (estaAberto && participacao) {
        form.defaults({
            carteira_id: participacao.carteiraId,
            categoria_id: participacao.categoriaId,
            executado: participacao.executado,
        });
        form.reset();
        form.clearErrors();
    }
});

/**
 * The participant changes only wallet, category and status of their own share (RN-080).
 */
function salvar(): void {
    if (props.item?.cotaId == null) {
        return;
    }

    form.put(update.url(props.item.cotaId), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (aberto.value = false),
    });
}

function pedirSaida(): void {
    if (props.item?.serie) {
        escolhendoEscopo.value = true;
    } else {
        confirmandoSaida.value = true;
    }
}

/**
 * Leaving gives the share back to the author (RN-081).
 */
function sair(escopo: EscopoSerie | null = null): void {
    if (props.item?.cotaId == null) {
        return;
    }

    router.delete(destroy.url(props.item.cotaId), {
        data: escopo ? { escopo } : {},
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            confirmandoSaida.value = false;
            escolhendoEscopo.value = false;
            aberto.value = false;
        },
    });
}
</script>

<template>
    <div>
        <Sheet v-model:open="aberto">
            <SheetContent class="w-full overflow-y-auto sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>{{ item?.descricao }}</SheetTitle>
                    <SheetDescription>
                        Dividido por {{ item?.autor }} · total
                        {{ formatarMoeda(item?.total ?? 0) }} · sua parte
                        {{ formatarMoeda(item?.valor ?? 0) }}
                    </SheetDescription>
                </SheetHeader>

                <form
                    v-if="item?.participacao"
                    class="grid gap-4 px-4 pb-6"
                    @submit.prevent="salvar"
                >
                    <p class="text-sm">
                        Data: {{ item.data.split('-').reverse().join('/') }}
                        <template v-if="item.parcela">
                            · parcela {{ item.parcela }}</template
                        >
                    </p>
                    <p
                        v-if="item.participacao.observacao"
                        class="rounded-md bg-muted p-3 text-sm whitespace-pre-line"
                    >
                        {{ item.participacao.observacao }}
                    </p>
                    <a
                        v-if="item.participacao.anexoNome"
                        :href="showAnexo.url(item.lancamentoId)"
                        class="text-sm underline"
                        target="_blank"
                    >
                        {{ item.participacao.anexoNome }}
                    </a>

                    <div class="grid gap-2">
                        <Label for="participacao_carteira">Carteira</Label>
                        <select
                            id="participacao_carteira"
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

                    <div class="grid gap-2">
                        <Label for="participacao_categoria">Categoria</Label>
                        <select
                            id="participacao_categoria"
                            v-model="form.categoria_id"
                            class="h-9 rounded-md border bg-transparent px-3 text-sm"
                        >
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

                    <label class="flex items-center gap-2">
                        <Checkbox
                            :model-value="form.executado"
                            @update:model-value="
                                form.executado = $event === true
                            "
                        />
                        Executado
                    </label>

                    <div class="flex flex-wrap gap-2">
                        <Button type="submit" :disabled="form.processing">
                            Salvar
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            class="ml-auto"
                            @click="pedirSaida"
                        >
                            Sair da divisão
                        </Button>
                    </div>
                </form>
            </SheetContent>
        </Sheet>

        <Dialog v-model:open="confirmandoSaida">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Sair da divisão?</DialogTitle>
                    <DialogDescription>
                        Sua parte volta para {{ item?.autor }}.
                    </DialogDescription>
                </DialogHeader>
                <InputError :message="page.props.errors.escopo" />
                <DialogFooter>
                    <Button variant="destructive" @click="sair()">Sair</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <EscopoSerieDialog
            v-model:open="escolhendoEscopo"
            acao="sair"
            :erro="page.props.errors.escopo"
            @confirmar="sair"
        />
    </div>
</template>
