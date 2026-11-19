<script setup lang="ts">
import CampoData from '@/components/CampoData.vue';
import CampoSelecao from '@/components/CampoSelecao.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { periodicidades } from '@/lib/periodicidades';
import { computed, ref, watch } from 'vue';
import LancamentoController from '@/actions/App/Http/Controllers/LancamentoController';
import InputError from '@/components/InputError.vue';
import DividirCampos from '@/components/lancamentos/DividirCampos.vue';
import type { Divisao } from '@/components/lancamentos/DividirCampos.vue';
import EscopoSerieDialog from '@/components/lancamentos/EscopoSerieDialog.vue';
import SerieAcoes from '@/components/lancamentos/SerieAcoes.vue';
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
import { useModoSimples } from '@/composables/useModoSimples';
import { dataCompleta, executadoPorPadrao } from '@/lib/datas';
import { vencimentoDaCompra } from '@/lib/faturas';
import { centavosDe, formatarCentavos, mascararValor } from '@/lib/dinheiro';
import {
    destroy as destroyAnexo,
    show as showAnexo,
} from '@/routes/lancamentos/anexo';
import type {
    CartaoDoFormulario,
    CarteiraResumo,
    CategoriaOpcao,
    ContatoOpcao,
    EscopoSerie,
    LancamentoEdicao,
    SerieDaParcela,
    TipoLancamento,
} from '@/types';

const aberto = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    lancamentoId: number | null;
    edicao: LancamentoEdicao | null;
    serie: SerieDaParcela | null;
    carteiraId: number;
    contatos: ContatoOpcao[];
    dataPadrao: string;
    hoje: string;
    carteiras: CarteiraResumo[];
    categorias: CategoriaOpcao[];
    /** Invoice data of each card of the user, by wallet id (RN-192). */
    cartoes?: Record<string, CartaoDoFormulario>;
    /** The entry is in a closed invoice (RN-200). */
    faturaFechada?: boolean;
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
    repetir: boolean;
    repeticao: 'parcelado' | 'recorrente';
    periodicidade: string;
    total_parcelas: number;
    modo_valor: 'total' | 'parcela';
    dia_vencimento: number | null;
    escopo: EscopoSerie | null;
};

const form = useForm<Campos>(camposIniciais());
const confirmandoExclusao = ref(false);
const escolhendoEscopo = ref<'editar' | 'excluir' | null>(null);
const page = usePage();
const autorId = computed(() => page.props.auth.user.id as number);
const divisao = ref<Divisao>(divisaoInicial());

function divisaoInicial(): Divisao {
    const rateio = props.edicao?.rateio;

    return {
        ativa: rateio != null,
        modo: rateio?.modo ?? 'iguais',
        autorParticipa: rateio?.autorParticipa ?? true,
        participantes: rateio
            ? rateio.participantes.map((participante) => ({
                  userId: participante.userId,
                  nome: participante.nome,
                  percentual: participante.percentual ?? '',
                  valor: formatarCentavos(participante.valor),
              }))
            : [
                  {
                      userId: autorId.value,
                      nome: 'Eu',
                      percentual: '',
                      valor: '',
                  },
              ],
    };
}

const bloqueado = computed(
    () => (props.edicao?.rateio?.bloqueado ?? false) || props.faturaFechada,
);

/**
 * In a card: no status, no split with contacts until Fase 15, and a hint with the invoice due date
 * (RN-194, RN-231, nota 07).
 */
const cartaoEscolhido = computed(
    () => props.cartoes?.[String(form.carteira_id)] ?? null,
);
const vencimentoDaFatura = computed(() =>
    cartaoEscolhido.value === null || props.faturaFechada
        ? null
        : vencimentoDaCompra(cartaoEscolhido.value, form.dataLancamento),
);
const anexoInput = ref<HTMLInputElement | null>(null);

const editando = computed(() => props.lancamentoId !== null);
const serie = computed(() => props.serie);

/**
 * New entries and simple entries may repeat (RF-050, RF-051, RF-057); installments may not (CA-057.3).
 */
const podeRepetir = computed(() => serie.value === null);

/**
 * Simple mode (RN-162): a new entry shows only type, description, value, date, category and the
 * status, in the wallet on screen. An entry that already has installments, a split, a note or an
 * attachment also shows those sections. Hidden fields keep their values.
 */
const modoSimples = useModoSimples();
const mostrarCarteira = computed(() => !modoSimples.value);
const mostrarRepetir = computed(() => podeRepetir.value && !modoSimples.value);
const mostrarDividir = computed(
    () => !modoSimples.value || props.edicao?.rateio != null,
);
const mostrarObservacao = computed(
    () => !modoSimples.value || Boolean(props.edicao?.observacao),
);
const mostrarAnexo = computed(
    () => !modoSimples.value || Boolean(props.edicao?.anexoNome),
);

const rotuloExecutado = computed(() => {
    if (!modoSimples.value) {
        return 'Executado';
    }

    return form.tipo === 'receita' ? 'Recebido' : 'Pago';
});

const rotuloValor = computed(() => {
    if (!form.repetir) {
        return 'Valor (R$)';
    }

    return form.repeticao === 'parcelado' && form.modo_valor === 'total'
        ? 'Valor total (R$)'
        : 'Valor da parcela (R$)';
});

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
        repetir: false,
        repeticao: 'parcelado',
        periodicidade: 'mensal',
        total_parcelas: 2,
        modo_valor: 'total',
        dia_vencimento: props.serie?.diaVencimento ?? null,
        escopo: null,
    };
}

watch(aberto, (estaAberto) => {
    if (estaAberto) {
        form.defaults(camposIniciais());
        form.reset();
        form.clearErrors();
        divisao.value = divisaoInicial();
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
 * Repetition fields go only when "Repetir" is on, and series never send a status (RN-013).
 */
function paraEnvio({
    dataLancamento,
    repetir,
    repeticao,
    periodicidade,
    total_parcelas,
    modo_valor,
    dia_vencimento,
    escopo,
    ...campos
}: Campos) {
    return {
        ...campos,
        data: dataLancamento,
        ...(repetir
            ? {
                  executado: editando.value ? campos.executado : null,
                  repeticao,
                  periodicidade,
                  ...(repeticao === 'parcelado'
                      ? { total_parcelas, modo_valor }
                      : {}),
              }
            : {}),
        ...(serie.value ? { dia_vencimento, escopo } : {}),
        ...(cartaoEscolhido.value !== null ? { executado: null } : {}),
        ...(divisao.value.ativa && cartaoEscolhido.value === null
            ? { rateio: rateioParaEnvio() }
            : {}),
    };
}

/**
 * Participants in inclusion order, the author first when sharing (RN-073).
 */
function rateioParaEnvio() {
    const { modo, autorParticipa, participantes } = divisao.value;
    const incluidos = participantes.filter(
        (participante) =>
            participante.userId !== autorId.value || autorParticipa,
    );

    return {
        modo,
        participantes: incluidos.map((participante) => ({
            user_id: participante.userId,
            ...(modo === 'percentual'
                ? { percentual: participante.percentual.replace(',', '.') }
                : {}),
            ...(modo === 'fixo' ? { valor: participante.valor } : {}),
        })),
    };
}

const erros = computed(() => form.errors as Record<string, string | undefined>);

function escolherAnexo(event: Event): void {
    form.anexo = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function salvar(adicionarOutro: boolean): void {
    if (serie.value && form.escopo === null) {
        escolhendoEscopo.value = 'editar';

        return;
    }

    const opcoes = {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            form.escopo = null;
        },
        onError: () => {
            escolhendoEscopo.value = null;
        },
        onSuccess: () => {
            escolhendoEscopo.value = null;

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
            divisao.value = divisaoInicial();

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

function pedirExclusao(): void {
    if (serie.value) {
        escolhendoEscopo.value = 'excluir';
    } else {
        confirmandoExclusao.value = true;
    }
}

function excluir(escopo: EscopoSerie | null = null): void {
    if (props.lancamentoId === null) {
        return;
    }

    router.delete(LancamentoController.destroy.url(props.lancamentoId), {
        data: escopo ? { escopo } : {},
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            confirmandoExclusao.value = false;
            escolhendoEscopo.value = null;
            aberto.value = false;
        },
    });
}

function confirmarEscopo(escopo: EscopoSerie): void {
    if (escolhendoEscopo.value === 'excluir') {
        excluir(escopo);

        return;
    }

    form.escopo = escopo;
    salvar(false);
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
                                :disabled="
                                    serie !== null || edicao?.rateio != null
                                "
                            />
                            Despesa
                        </label>
                        <label class="flex items-center gap-2">
                            <input
                                v-model="form.tipo"
                                type="radio"
                                value="receita"
                                :disabled="
                                    serie !== null || edicao?.rateio != null
                                "
                            />
                            Receita
                        </label>
                    </fieldset>
                    <InputError :message="form.errors.tipo" />

                    <SerieAcoes
                        v-if="serie"
                        :serie="serie"
                        :hoje="hoje"
                        :com-cota="edicao?.rateio?.autorParticipa ?? true"
                        :alteracoes-pendentes="form.isDirty"
                        @concluido="aberto = false"
                    />

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
                            <Label for="valor">{{ rotuloValor }}</Label>
                            <Input
                                id="valor"
                                :model-value="form.valor"
                                inputmode="numeric"
                                placeholder="0,00"
                                :disabled="bloqueado"
                                required
                                @update:model-value="
                                    form.valor = mascararValor(String($event))
                                "
                            />
                            <InputError :message="form.errors.valor" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="data">Data</Label>
                            <CampoData
                                id="data"
                                v-model="form.dataLancamento"
                                :disabled="faturaFechada"
                            />
                            <p
                                v-if="cartaoEscolhido && vencimentoDaFatura"
                                class="text-xs text-muted-foreground"
                            >
                                Entra na fatura que vence em
                                {{ dataCompleta(vencimentoDaFatura) }}.
                            </p>
                            <p
                                v-else-if="cartaoEscolhido && !faturaFechada"
                                class="text-xs text-destructive"
                            >
                                Essa data cai numa fatura fechada. Use uma data
                                a partir de
                                {{ dataCompleta(cartaoEscolhido.abertaDesde) }}.
                            </p>
                            <InputError :message="erros.data" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="categoria_id">Categoria</Label>
                        <CampoSelecao
                            id="categoria_id"
                            v-model="form.categoria_id"
                            required
                            placeholder="Selecione"
                            :opcoes="
                                categoriasDoTipo.map((categoria) => ({
                                    valor: categoria.id,
                                    rotulo: categoria.nome,
                                }))
                            "
                        />
                        <InputError :message="form.errors.categoria_id" />
                    </div>

                    <div v-if="mostrarCarteira" class="grid gap-2">
                        <Label for="carteira_id">Carteira</Label>
                        <CampoSelecao
                            id="carteira_id"
                            v-model="form.carteira_id"
                            :disabled="faturaFechada"
                            :opcoes="
                                carteiras.map((carteira) => ({
                                    valor: carteira.id,
                                    rotulo: carteira.nome,
                                }))
                            "
                        />
                        <InputError :message="form.errors.carteira_id" />
                    </div>

                    <div v-if="serie" class="grid gap-2">
                        <Label for="dia_vencimento">Dia de vencimento</Label>
                        <Input
                            id="dia_vencimento"
                            :model-value="form.dia_vencimento ?? undefined"
                            @update:model-value="
                                form.dia_vencimento = Number($event) || null
                            "
                            type="number"
                            min="1"
                            max="31"
                        />
                        <p class="text-xs text-muted-foreground">
                            Muda só nas opções "esta e as próximas" e "todas".
                        </p>
                        <InputError :message="erros.dia_vencimento" />
                    </div>

                    <div
                        v-if="mostrarRepetir"
                        class="grid gap-3 rounded-md border p-3"
                    >
                        <label class="flex items-center gap-2 font-medium">
                            <Checkbox
                                :model-value="form.repetir"
                                @update:model-value="
                                    form.repetir = $event === true
                                "
                            />
                            Repetir
                        </label>
                        <template v-if="form.repetir">
                            <fieldset class="flex gap-4">
                                <legend class="sr-only">Forma</legend>
                                <label class="flex items-center gap-2">
                                    <input
                                        v-model="form.repeticao"
                                        type="radio"
                                        value="parcelado"
                                    />
                                    Parcelado
                                </label>
                                <label class="flex items-center gap-2">
                                    <input
                                        v-model="form.repeticao"
                                        type="radio"
                                        value="recorrente"
                                    />
                                    Recorrente
                                </label>
                            </fieldset>
                            <InputError :message="erros.repeticao" />
                            <div class="grid grid-cols-2 gap-4">
                                <div class="grid gap-2">
                                    <Label for="periodicidade"
                                        >Periodicidade</Label
                                    >
                                    <CampoSelecao
                                        id="periodicidade"
                                        v-model="form.periodicidade"
                                        :opcoes="
                                            periodicidades.map(
                                                (periodicidade) => ({
                                                    valor: periodicidade.valor,
                                                    rotulo: periodicidade.titulo,
                                                }),
                                            )
                                        "
                                    />
                                    <InputError
                                        :message="erros.periodicidade"
                                    />
                                </div>
                                <div
                                    v-if="form.repeticao === 'parcelado'"
                                    class="grid gap-2"
                                >
                                    <Label for="total_parcelas"
                                        >Nº de parcelas</Label
                                    >
                                    <Input
                                        id="total_parcelas"
                                        v-model.number="form.total_parcelas"
                                        type="number"
                                        min="2"
                                        max="360"
                                    />
                                    <InputError
                                        :message="erros.total_parcelas"
                                    />
                                </div>
                            </div>
                            <fieldset
                                v-if="form.repeticao === 'parcelado'"
                                class="flex gap-4"
                            >
                                <legend class="sr-only">Valor informado</legend>
                                <label class="flex items-center gap-2">
                                    <input
                                        v-model="form.modo_valor"
                                        type="radio"
                                        value="total"
                                    />
                                    Valor total
                                </label>
                                <label class="flex items-center gap-2">
                                    <input
                                        v-model="form.modo_valor"
                                        type="radio"
                                        value="parcela"
                                    />
                                    Valor da parcela
                                </label>
                            </fieldset>
                            <InputError :message="erros.modo_valor" />
                        </template>
                    </div>

                    <p
                        v-if="faturaFechada"
                        class="rounded-md border border-dashed p-3 text-sm text-muted-foreground"
                    >
                        Fatura fechada: só descrição, categoria, observação e
                        anexo podem mudar.
                    </p>

                    <label
                        v-if="mostrarDividir && cartaoEscolhido"
                        class="grid gap-1 text-sm text-muted-foreground"
                    >
                        <span class="flex items-center gap-2">
                            <Checkbox :model-value="false" disabled />
                            Dividir
                        </span>
                        Ainda não é possível dividir compras do cartão com
                        contatos.
                    </label>
                    <DividirCampos
                        v-else-if="mostrarDividir"
                        v-model="divisao"
                        :contatos="contatos"
                        :autor-id="autorId"
                        :total="centavosDe(form.valor)"
                        :bloqueado="bloqueado"
                        :erro="erros.rateio"
                    />

                    <label
                        v-if="
                            !form.repetir &&
                            cartaoEscolhido === null &&
                            (!divisao.ativa || divisao.autorParticipa)
                        "
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            :model-value="executadoExibido"
                            @update:model-value="
                                form.executado = $event === true
                            "
                        />
                        {{ rotuloExecutado }}
                    </label>
                    <InputError :message="form.errors.executado" />

                    <div v-if="mostrarObservacao" class="grid gap-2">
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

                    <div v-if="mostrarAnexo" class="grid gap-2">
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
                            v-if="!editando && !modoSimples"
                            type="button"
                            variant="secondary"
                            :disabled="form.processing"
                            @click="salvar(true)"
                        >
                            Salvar e adicionar outro
                        </Button>
                        <Button
                            v-if="editando && !faturaFechada"
                            type="button"
                            variant="destructive"
                            class="ml-auto"
                            @click="pedirExclusao"
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
                    <Button variant="destructive" @click="excluir()"
                        >Excluir</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <EscopoSerieDialog
            :open="escolhendoEscopo !== null"
            :acao="escolhendoEscopo ?? 'editar'"
            :erro="erros.escopo ?? page.props.errors.lancamento"
            :processando="form.processing"
            @update:open="!$event && (escolhendoEscopo = null)"
            @confirmar="confirmarEscopo"
        />
    </div>
</template>
