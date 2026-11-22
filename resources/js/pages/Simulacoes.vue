<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Minus, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import BotaoPrivacidade from '@/components/BotaoPrivacidade.vue';
import CampoSelecao from '@/components/CampoSelecao.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { centavosDe, formatarMoeda, mascararValor } from '@/lib/dinheiro';
import { simulacoes } from '@/routes';

type Saldos = { atual: number; estimado: number };
type Operacao = { id: number; valor: number };

const props = defineProps<{
    carteiraId: number | null;
    carteiras: { id: number; nome: string }[];
    saldos: Saldos | null;
}>();

const valor = ref('');
const operacoes = ref<Operacao[]>([]);
const carregandoCarteira = ref(false);
let proximaOperacao = 1;

const valorEmCentavos = computed(() => centavosDe(valor.value));
const ajuste = computed(() =>
    operacoes.value.reduce((total, operacao) => total + operacao.valor, 0),
);
const opcoes = computed(() =>
    props.carteiras.map((carteira) => ({
        valor: carteira.id,
        rotulo: carteira.nome,
    })),
);

const rotulos = [
    { chave: 'atual', texto: 'Saldo atual' },
    { chave: 'estimado', texto: 'Saldo estimado' },
] as const;

function trocarCarteira(id: number | null): void {
    if (id === null || id === props.carteiraId) {
        return;
    }

    router.get(
        simulacoes.url(),
        { carteira: id },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                carregandoCarteira.value = true;
            },
            onFinish: () => {
                carregandoCarteira.value = false;
            },
        },
    );
}

function adicionarOperacao(sinal: 1 | -1): void {
    if (valorEmCentavos.value <= 0 || props.saldos === null) {
        return;
    }

    operacoes.value.push({
        id: proximaOperacao++,
        valor: sinal * valorEmCentavos.value,
    });
    valor.value = '';
}

function removerOperacao(id: number): void {
    operacoes.value = operacoes.value.filter((operacao) => operacao.id !== id);
}
</script>

<template>
    <Head title="Simulações" />

    <div class="mx-auto grid w-full max-w-5xl gap-4 p-4 md:gap-6 md:p-6">
        <header class="flex items-center justify-between gap-3">
            <h1 class="font-display text-2xl font-semibold tracking-tight">
                Simulações
            </h1>
            <BotaoPrivacidade />
        </header>

        <p
            v-if="!saldos"
            class="rounded-2xl border border-dashed p-6 text-sm text-muted-foreground"
        >
            Nenhuma carteira comum está disponível para simular.
        </p>

        <template v-else>
            <div class="grid gap-2 sm:max-w-xs">
                <Label for="carteira-simulacao">Carteira</Label>
                <CampoSelecao
                    id="carteira-simulacao"
                    :model-value="carteiraId"
                    :opcoes="opcoes"
                    :disabled="carregandoCarteira"
                    @update:model-value="trocarCarteira"
                />
            </div>

            <section
                aria-label="Comparação de saldos"
                :aria-busy="carregandoCarteira"
                aria-live="polite"
                class="grid gap-5 rounded-3xl bg-saldo p-5 text-saldo-foreground shadow-sm sm:grid-cols-2 md:p-6"
            >
                <div
                    v-for="{ chave, texto } in rotulos"
                    :key="chave"
                    class="grid min-w-0 gap-2"
                >
                    <h2 class="text-sm font-medium">{{ texto }}</h2>
                    <dl
                        class="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] items-end gap-3"
                    >
                        <div class="grid gap-0.5 text-saldo-foreground/75">
                            <dt class="text-sm">Real</dt>
                            <dd class="font-medium break-words tabular-nums">
                                {{ formatarMoeda(saldos[chave]) }}
                            </dd>
                        </div>
                        <div class="grid gap-0.5">
                            <dt class="text-sm text-saldo-foreground/75">
                                Simulado
                            </dt>
                            <dd
                                class="font-display text-2xl font-semibold tracking-tight break-words tabular-nums lg:text-3xl"
                            >
                                {{ formatarMoeda(saldos[chave] + ajuste) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </section>

            <div
                class="grid items-start gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]"
            >
                <section class="grid gap-3 rounded-2xl border bg-card p-4">
                    <form
                        class="grid gap-3"
                        @submit.prevent="adicionarOperacao(1)"
                    >
                        <div class="grid gap-2">
                            <Label for="valor-simulacao">Valor (R$)</Label>
                            <Input
                                id="valor-simulacao"
                                :model-value="valor"
                                inputmode="numeric"
                                autocomplete="off"
                                placeholder="0,00"
                                @update:model-value="
                                    valor = mascararValor(String($event))
                                "
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <Button
                                type="submit"
                                :disabled="valorEmCentavos <= 0"
                            >
                                <Plus aria-hidden="true" /> Somar
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="valorEmCentavos <= 0"
                                @click="adicionarOperacao(-1)"
                            >
                                <Minus aria-hidden="true" /> Subtrair
                            </Button>
                        </div>
                    </form>
                </section>

                <section class="grid gap-3 rounded-2xl border bg-card p-4">
                    <header
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2 class="text-base font-semibold">Operações</h2>
                        <Button
                            v-if="operacoes.length > 0"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="operacoes = []"
                        >
                            Limpar operações
                        </Button>
                    </header>
                    <p
                        v-if="operacoes.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Digite um valor para somar ou subtrair.
                    </p>
                    <ol v-else class="divide-y rounded-xl border">
                        <li
                            v-for="(operacao, indice) in operacoes"
                            :key="operacao.id"
                            class="flex items-center justify-between gap-2 px-3 py-1.5 text-sm"
                        >
                            <span class="text-muted-foreground">
                                {{ operacao.valor > 0 ? 'Entrada' : 'Saída' }}
                            </span>
                            <span class="ml-auto font-medium tabular-nums">
                                {{ formatarMoeda(operacao.valor) }}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="'Remover operação ' + (indice + 1)"
                                @click="removerOperacao(operacao.id)"
                            >
                                <X aria-hidden="true" />
                            </Button>
                        </li>
                    </ol>
                </section>
            </div>
        </template>
    </div>
</template>
