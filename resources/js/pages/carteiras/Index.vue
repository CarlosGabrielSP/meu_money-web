<script setup lang="ts">
import CampoSelecao from '@/components/CampoSelecao.vue';
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CarteiraController from '@/actions/App/Http/Controllers/CarteiraController';
import CarteiraMembroController from '@/actions/App/Http/Controllers/CarteiraMembroController';
import CarteiraCampos from '@/components/carteiras/CarteiraCampos.vue';
import type { DadosCartao } from '@/components/carteiras/CarteiraCampos.vue';
import CarteiraIcone from '@/components/carteiras/CarteiraIcone.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useModoSimples } from '@/composables/useModoSimples';
import { home } from '@/routes';
import { index, padrao } from '@/routes/carteiras';
import { index as contatosIndex } from '@/routes/contatos';

type Membro = {
    id: number;
    userId: number;
    nome: string;
    status: 'pendente' | 'aceito' | 'recusado';
};

type Delegada = {
    id: number;
    carteiraId: number;
    nome: string;
    cor: string;
    icone: string;
    donoId: number;
    dono: string;
};

type Carteira = {
    id: number;
    nome: string;
    tipo: 'comum' | 'cartao';
    padrao: boolean;
    cor: string;
    icone: string;
    temConteudo: boolean;
    temCotas: boolean;
    membros: Membro[];
    espelho: boolean;
    cartao:
        | (DadosCartao & {
              carteiraVinculadaNome: string | null;
              autorDoEspelho: string | null;
              contatosDosEspelhos: string[];
          })
        | null;
};

const props = defineProps<{
    carteiras: Carteira[];
    contatos: { id: number; nome: string }[];
    delegadas: { convites: Delegada[]; carteiras: Delegada[] };
    cores: string[];
    icones: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Carteiras', href: index() }],
    },
});

const criando = ref(false);
const editando = ref<Carteira | null>(null);
const excluindo = ref<Carteira | null>(null);
const modoExclusao = ref<'mover' | 'excluir'>('mover');

const destinoId = ref<number | null>(null);
const modoSimples = useModoSimples();

const carteirasComuns = computed(() =>
    props.carteiras.filter((carteira) => carteira.tipo === 'comum'),
);

/**
 * A common wallet moves only to another common wallet; a card to any other except a mirror
 * (DEC-087, RN-262).
 */
function destinosDe(carteira: Carteira): Carteira[] {
    return props.carteiras.filter(
        (outra) =>
            outra.id !== carteira.id &&
            !outra.cartao?.autorDoEspelho &&
            (carteira.tipo === 'cartao' || outra.tipo === 'comum'),
    );
}

const destinosPossiveis = computed(() =>
    excluindo.value === null ? [] : destinosDe(excluindo.value),
);

function abrirExclusao(carteira: Carteira): void {
    modoExclusao.value = carteira.temConteudo ? 'mover' : 'excluir';
    excluindo.value = carteira;
    destinoId.value = destinosDe(carteira)[0]?.id ?? null;
}

function resumoDoCartao(carteira: Carteira): string {
    const cartao = carteira.cartao;

    if (cartao === null) {
        return '';
    }

    return `Vence dia ${cartao.diaVencimento} · ${cartao.carteiraVinculadaNome === null ? 'independente' : `paga por ${cartao.carteiraVinculadaNome}`}`;
}

/**
 * "B", "B e C", "B, C e D".
 */
function juntarNomes(nomes: string[]): string {
    return nomes.length < 2
        ? (nomes[0] ?? '')
        : `${nomes.slice(0, -1).join(', ')} e ${nomes[nomes.length - 1]}`;
}

/**
 * The contact never deletes a mirror with parts (RN-237, nota 07).
 */
function espelhoComPartes(carteira: Carteira): boolean {
    return Boolean(carteira.cartao?.autorDoEspelho) && carteira.temCotas;
}

const gerenciandoId = ref<number | null>(null);
const erroDeNovoConvite = ref<string | null>(null);
const convidadoId = ref<number | null>(null);

/**
 * The wallet whose members dialog is open, kept fresh from the props after each action.
 */
const gerenciando = computed(
    () =>
        props.carteiras.find(
            (carteira) => carteira.id === gerenciandoId.value,
        ) ?? null,
);

/**
 * Contacts without any record in the wallet; a refused one uses "Convidar de novo" (DEC-098).
 */
const contatosParaConvidar = computed(() =>
    gerenciando.value === null
        ? []
        : props.contatos.filter(
              (contato) =>
                  !gerenciando.value!.membros.some(
                      (membro) => membro.userId === contato.id,
                  ),
          ),
);

function abrirMembros(carteira: Carteira): void {
    gerenciandoId.value = carteira.id;
    convidadoId.value = null;
    erroDeNovoConvite.value = null;
}

function membrosAceitos(carteira: Carteira): number {
    return carteira.membros.filter((membro) => membro.status === 'aceito')
        .length;
}

/**
 * "1 membro", "2 membros"; counts only accepted members (DEC-098).
 */
function rotuloDeMembros(carteira: Carteira): string {
    const total = membrosAceitos(carteira);

    return `${total} ${total === 1 ? 'membro' : 'membros'}`;
}

const situacaoDoMembro: Record<Membro['status'], string> = {
    aceito: 'Membro',
    pendente: 'Convite pendente',
    recusado: 'Recusou',
};

function convidarDeNovo(carteira: Carteira, membro: Membro): void {
    router.post(
        CarteiraMembroController.store(carteira.id).url,
        { user_id: membro.userId },
        {
            preserveScroll: true,
            onStart: () => (erroDeNovoConvite.value = null),
            onError: (errors) =>
                (erroDeNovoConvite.value = errors.user_id ?? null),
        },
    );
}

function cancelarConvite(membro: Membro): void {
    router.delete(CarteiraMembroController.destroy(membro.id).url, {
        preserveScroll: true,
    });
}

function responderConvite(convite: Delegada, aceitar: boolean): void {
    const rota = aceitar
        ? CarteiraMembroController.aceitar(convite.id)
        : CarteiraMembroController.recusar(convite.id);

    router.post(rota.url, {}, { preserveScroll: true });
}

/**
 * Removing a member or leaving asks for confirmation (nota 07).
 */
const confirmando = ref<{ id: number; titulo: string; texto: string } | null>(
    null,
);

function confirmarRemocao(carteira: Carteira, membro: Membro): void {
    confirmando.value = {
        id: membro.id,
        titulo: `Remover ${membro.nome}?`,
        texto: `${membro.nome} perde o acesso a ${carteira.nome}. Os lançamentos ficam.`,
    };
}

function confirmarSaida(delegada: Delegada): void {
    confirmando.value = {
        id: delegada.id,
        titulo: `Sair de ${delegada.nome}?`,
        texto: `Você perde o acesso a ${delegada.nome} de ${delegada.dono}.`,
    };
}

function confirmar(): void {
    if (confirmando.value === null) {
        return;
    }

    router.delete(CarteiraMembroController.destroy(confirmando.value.id).url, {
        preserveScroll: true,
        onSuccess: () => (confirmando.value = null),
    });
}

/**
 * "Delegadas a mim" grouped by owner, already sorted by the server (DEC-098).
 */
const delegadasPorDono = computed(() => {
    const grupos = new Map<number, Delegada[]>();

    for (const delegada of props.delegadas.carteiras) {
        grupos.set(delegada.donoId, [
            ...(grupos.get(delegada.donoId) ?? []),
            delegada,
        ]);
    }

    return [...grupos.entries()].map(([donoId, carteiras]) => ({
        donoId,
        dono: carteiras[0].dono,
        carteiras,
    }));
});

function tornarPadrao(carteira: Carteira): void {
    router.post(padrao(carteira.id).url, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Carteiras" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Carteiras"
                description="Organize seus lançamentos por conta, cartão ou dinheiro"
            />
            <Button data-test="nova-carteira-button" @click="criando = true">
                Nova carteira
            </Button>
        </div>

        <ul class="divide-y overflow-hidden rounded-2xl border bg-card">
            <li
                v-for="carteira in carteiras"
                :key="carteira.id"
                class="flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <CarteiraIcone
                        :cor="carteira.cor"
                        :icone="carteira.icone"
                    />
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ carteira.nome }}</p>
                        <p
                            v-if="carteira.cartao"
                            class="truncate text-sm text-muted-foreground"
                        >
                            {{ resumoDoCartao(carteira) }}
                            <template v-if="membrosAceitos(carteira) > 0">
                                · {{ rotuloDeMembros(carteira) }}
                            </template>
                        </p>
                        <p
                            v-else-if="membrosAceitos(carteira) > 0"
                            class="truncate text-sm text-muted-foreground"
                        >
                            {{ rotuloDeMembros(carteira) }}
                        </p>
                    </div>
                    <Badge v-if="carteira.padrao" variant="secondary"
                        >Padrão</Badge
                    >
                    <Badge
                        v-if="carteira.cartao?.autorDoEspelho"
                        variant="outline"
                        >Cartão de {{ carteira.cartao.autorDoEspelho }}</Badge
                    >
                    <Badge
                        v-else-if="carteira.tipo === 'cartao'"
                        variant="outline"
                        >Cartão</Badge
                    >
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="!carteira.padrao && carteira.tipo === 'comum'"
                        variant="outline"
                        size="sm"
                        @click="tornarPadrao(carteira)"
                    >
                        Tornar padrão
                    </Button>
                    <Button
                        v-if="!carteira.espelho && !modoSimples"
                        variant="outline"
                        size="sm"
                        @click="abrirMembros(carteira)"
                    >
                        Membros
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="editando = carteira"
                    >
                        Editar
                    </Button>
                    <Button
                        v-if="!carteira.padrao"
                        variant="destructive"
                        size="sm"
                        :disabled="espelhoComPartes(carteira)"
                        @click="abrirExclusao(carteira)"
                    >
                        Excluir
                    </Button>
                </div>
                <p
                    v-if="espelhoComPartes(carteira)"
                    class="text-sm text-muted-foreground sm:basis-full"
                >
                    Este cartão tem compras divididas com
                    {{ carteira.cartao?.autorDoEspelho }}.
                </p>
            </li>
        </ul>

        <!-- Wallets delegated to the user and member invitations (RF-201, RF-202, RN-279). -->
        <section
            v-if="delegadas.convites.length || delegadas.carteiras.length"
            class="flex flex-col gap-3"
        >
            <h2
                class="text-sm font-semibold tracking-wide text-muted-foreground uppercase"
            >
                Delegadas a mim
            </h2>
            <div v-if="delegadas.convites.length" class="flex flex-col gap-2">
                <h3 class="text-sm font-medium">Convites</h3>
                <ul class="divide-y overflow-hidden rounded-2xl border bg-card">
                    <li
                        v-for="convite in delegadas.convites"
                        :key="convite.id"
                        class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <CarteiraIcone
                                :cor="convite.cor"
                                :icone="convite.icone"
                            />
                            <p class="truncate font-medium">
                                {{ convite.nome }} · de {{ convite.dono }}
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="responderConvite(convite, false)"
                            >
                                Recusar
                            </Button>
                            <Button
                                size="sm"
                                @click="responderConvite(convite, true)"
                            >
                                Aceitar
                            </Button>
                        </div>
                    </li>
                </ul>
            </div>
            <div
                v-for="grupo in delegadasPorDono"
                :key="grupo.donoId"
                class="flex flex-col gap-2"
            >
                <h3 class="text-sm font-medium">{{ grupo.dono }}</h3>
                <ul class="divide-y overflow-hidden rounded-2xl border bg-card">
                    <li
                        v-for="delegada in grupo.carteiras"
                        :key="delegada.id"
                        class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <CarteiraIcone
                                :cor="delegada.cor"
                                :icone="delegada.icone"
                            />
                            <p class="truncate font-medium">
                                {{ delegada.nome }}
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <Button variant="outline" size="sm" as-child>
                                <Link
                                    :href="
                                        home({
                                            query: {
                                                carteira: delegada.carteiraId,
                                            },
                                        })
                                    "
                                    >Abrir</Link
                                >
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="confirmarSaida(delegada)"
                            >
                                Sair
                            </Button>
                        </div>
                    </li>
                </ul>
            </div>
        </section>
    </div>

    <!-- Members of an owner's wallet or own card (RF-200, RF-202, RF-211, nota 07). -->
    <Dialog
        :open="gerenciando !== null"
        @update:open="gerenciandoId = $event ? gerenciandoId : null"
    >
        <DialogContent v-if="gerenciando">
            <DialogHeader>
                <DialogTitle>Membros de {{ gerenciando.nome }}</DialogTitle>
                <DialogDescription v-if="gerenciando.tipo === 'cartao'">
                    Membros lançam, editam, excluem e pagam a fatura deste
                    cartão em seu nome. A fatura continua só sua.
                </DialogDescription>
                <DialogDescription v-else>
                    Membros lançam, editam e excluem nesta carteira em seu nome.
                    O saldo continua só seu.
                </DialogDescription>
            </DialogHeader>

            <ul
                v-if="gerenciando.membros.length"
                class="divide-y rounded-xl border"
            >
                <li
                    v-for="membro in gerenciando.membros"
                    :key="membro.id"
                    class="flex items-center justify-between gap-3 p-3"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ membro.nome }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ situacaoDoMembro[membro.status] }}
                        </p>
                    </div>
                    <Button
                        v-if="membro.status === 'aceito'"
                        variant="outline"
                        size="sm"
                        @click="confirmarRemocao(gerenciando, membro)"
                    >
                        Remover
                    </Button>
                    <Button
                        v-else-if="membro.status === 'pendente'"
                        variant="outline"
                        size="sm"
                        @click="cancelarConvite(membro)"
                    >
                        Cancelar
                    </Button>
                    <Button
                        v-else
                        variant="outline"
                        size="sm"
                        @click="convidarDeNovo(gerenciando, membro)"
                    >
                        Convidar de novo
                    </Button>
                </li>
            </ul>

            <!-- "Convidar de novo" posts outside the form; its error (e.g. a deactivated contact) shows here. -->
            <InputError :message="erroDeNovoConvite ?? undefined" />
            <p v-if="!contatos.length" class="text-sm text-muted-foreground">
                Adicione um contato antes de convidar.
                <Link :href="contatosIndex()" class="underline">Contatos</Link>
            </p>
            <Form
                v-else-if="contatosParaConvidar.length"
                v-bind="CarteiraMembroController.store.form(gerenciando.id)"
                class="grid gap-2"
                :options="{ preserveScroll: true }"
                @success="convidadoId = null"
                v-slot="{ errors, processing }"
            >
                <Label for="user_id">Convidar</Label>
                <div class="flex gap-2">
                    <CampoSelecao
                        id="user_id"
                        v-model="convidadoId"
                        name="user_id"
                        class="flex-1"
                        placeholder="Escolha um contato"
                        :opcoes="
                            contatosParaConvidar.map((contato) => ({
                                valor: contato.id,
                                rotulo: contato.nome,
                            }))
                        "
                    />
                    <Button :disabled="processing || convidadoId === null">
                        Convidar
                    </Button>
                </div>
                <InputError :message="errors.user_id ?? errors.carteira" />
            </Form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="confirmando !== null"
        @update:open="confirmando = $event ? confirmando : null"
    >
        <DialogContent v-if="confirmando">
            <DialogHeader>
                <DialogTitle>{{ confirmando.titulo }}</DialogTitle>
                <DialogDescription>{{ confirmando.texto }}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="confirmando = null">
                    Voltar
                </Button>
                <Button variant="destructive" @click="confirmar">
                    {{
                        confirmando.titulo.startsWith('Sair')
                            ? 'Sair'
                            : 'Remover'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog :open="criando" @update:open="criando = $event">
        <DialogContent>
            <Form
                v-bind="CarteiraController.store.form()"
                class="space-y-6"
                @success="criando = false"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Nova carteira</DialogTitle>
                </DialogHeader>
                <CarteiraCampos
                    :cores="cores"
                    :icones="icones"
                    :errors="errors"
                    :carteiras-comuns="carteirasComuns"
                    :simples="modoSimples"
                />
                <DialogFooter>
                    <Button :disabled="processing">Criar carteira</Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="editando !== null"
        @update:open="editando = $event ? editando : null"
    >
        <DialogContent v-if="editando">
            <Form
                v-bind="CarteiraController.update.form(editando.id)"
                class="space-y-6"
                @success="editando = null"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Editar carteira</DialogTitle>
                </DialogHeader>
                <CarteiraCampos
                    :cores="cores"
                    :icones="icones"
                    :errors="errors"
                    :nome="editando.nome"
                    :cor="editando.cor"
                    :icone="editando.icone"
                    :tipo="editando.tipo"
                    :cartao="editando.cartao"
                    :carteiras-comuns="carteirasComuns"
                    :simples="modoSimples"
                    :autor-do-espelho="editando.cartao?.autorDoEspelho ?? null"
                />
                <DialogFooter>
                    <Button :disabled="processing">Salvar</Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="excluindo !== null"
        @update:open="excluindo = $event ? excluindo : null"
    >
        <DialogContent v-if="excluindo">
            <Form
                v-bind="CarteiraController.destroy.form(excluindo.id)"
                class="space-y-6"
                @success="excluindo = null"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Excluir {{ excluindo.nome }}?</DialogTitle>
                    <DialogDescription v-if="!excluindo.temConteudo">
                        A carteira está vazia.
                    </DialogDescription>
                    <DialogDescription v-else>
                        A carteira tem lançamentos. Escolha o que fazer com
                        eles.
                        <template v-if="excluindo.tipo === 'cartao'">
                            As compras de faturas fechadas também vão junto.
                        </template>
                        <!-- The contacts' parts follow the purchases (RF-190, nota 07, DEC-092). -->
                        <template
                            v-if="excluindo.cartao?.contatosDosEspelhos.length"
                        >
                            As partes de
                            {{
                                juntarNomes(
                                    excluindo.cartao.contatosDosEspelhos,
                                )
                            }}
                            vão junto: para o cartão de destino, para a carteira
                            de destino como rateio comum, ou são excluídas com
                            os pagamentos deles.
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <input type="hidden" name="modo" :value="modoExclusao" />

                <div v-if="excluindo.temConteudo" class="grid gap-4">
                    <label class="flex items-center gap-2">
                        <input
                            v-model="modoExclusao"
                            type="radio"
                            value="mover"
                        />
                        Mover os lançamentos para outra carteira
                    </label>
                    <div
                        v-if="modoExclusao === 'mover'"
                        class="grid gap-2 pl-6"
                    >
                        <Label for="destino_id">Carteira de destino</Label>
                        <CampoSelecao
                            id="destino_id"
                            v-model="destinoId"
                            name="destino_id"
                            :opcoes="
                                destinosPossiveis.map((destino) => ({
                                    valor: destino.id,
                                    rotulo: destino.nome,
                                }))
                            "
                        />
                        <InputError :message="errors.destino_id" />
                    </div>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="modoExclusao"
                            type="radio"
                            value="excluir"
                        />
                        Excluir os lançamentos junto
                    </label>
                </div>
                <InputError :message="errors.carteira" />

                <DialogFooter>
                    <Button variant="destructive" :disabled="processing">
                        Excluir carteira
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
