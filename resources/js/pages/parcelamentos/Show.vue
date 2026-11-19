<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import { coresCarteira } from '@/components/carteiras/aparencia';
import { iconesCategoria } from '@/components/categorias/icones';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dataCompleta } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import { tituloDaPeriodicidade } from '@/lib/periodicidades';

type ParcelaRastreada = {
    numero: number;
    data: string;
    carteira: { nome: string; cor: string };
    valor: number;
    executado: boolean;
    vencida: boolean;
    /** Purchase date of an installment in a card, dated by the invoice due date (RN-214). */
    dataCompra: string | null;
};

const props = defineProps<{
    serie: {
        id: number;
        descricao: string;
        tipo: 'receita' | 'despesa';
        periodicidade: string;
        recorrente: boolean;
        totalParcelas: number | null;
        categoria: { nome: string; icone: string } | null;
    };
    resumo: {
        total: number | null;
        pago: number;
        pendente: number;
        pagas: number;
        existentes: number;
    };
    parcelas: ParcelaRastreada[];
    /** Page of the app the user came from, or the home screen (note 07). */
    voltarPara: string;
}>();

const progresso = computed(() =>
    props.resumo.existentes === 0
        ? 0
        : Math.round((props.resumo.pagas / props.resumo.existentes) * 100),
);

const periodicidade = computed(() =>
    tituloDaPeriodicidade(props.serie.periodicidade),
);

/** "Pago" for expenses and "Recebido" for income, as in the simple mode (RN-159). */
const rotuloExecutado = computed(() =>
    props.serie.tipo === 'despesa' ? 'Pago' : 'Recebido',
);

const iconeCategoria = computed(
    () => iconesCategoria[props.serie.categoria?.icone ?? 'tag'],
);

function numeroDaParcela(parcela: ParcelaRastreada): string {
    return props.serie.totalParcelas === null
        ? String(parcela.numero)
        : `${parcela.numero}/${props.serie.totalParcelas}`;
}
</script>

<template>
    <Head :title="serie.descricao" />

    <!-- Read-only tracking of a series (RN-213, RN-215, DEC-082). -->
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 md:gap-6">
        <header class="grid gap-3">
            <div class="flex items-center gap-2">
                <Button variant="ghost" size="icon" as-child>
                    <Link :href="voltarPara" aria-label="Voltar">
                        <ArrowLeft class="size-5" />
                    </Link>
                </Button>
                <h1
                    class="min-w-0 truncate font-display text-2xl font-semibold tracking-tight"
                >
                    {{ serie.descricao }}
                </h1>
            </div>
            <div class="flex flex-wrap gap-2">
                <Badge variant="outline">{{
                    serie.tipo === 'despesa' ? 'Despesa' : 'Receita'
                }}</Badge>
                <Badge v-if="serie.categoria" variant="outline">
                    <component :is="iconeCategoria" class="size-3.5" />
                    {{ serie.categoria.nome }}
                </Badge>
                <Badge variant="outline">{{ periodicidade }}</Badge>
            </div>
        </header>

        <section
            class="grid gap-4 rounded-2xl border bg-card p-4 md:p-5"
            aria-label="Resumo"
        >
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <div v-if="resumo.total !== null" class="grid gap-0.5">
                    <dt class="text-sm text-muted-foreground">Total</dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ formatarMoeda(resumo.total) }}
                    </dd>
                </div>
                <div class="grid gap-0.5">
                    <dt class="text-sm text-muted-foreground">Pago</dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ formatarMoeda(resumo.pago) }}
                    </dd>
                </div>
                <div class="grid gap-0.5">
                    <dt class="text-sm text-muted-foreground">Pendente</dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ formatarMoeda(resumo.pendente) }}
                    </dd>
                </div>
            </dl>
            <div class="grid gap-1.5">
                <div
                    class="h-2 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-valuenow="resumo.pagas"
                    :aria-valuemin="0"
                    :aria-valuemax="resumo.existentes"
                    :aria-label="`${resumo.pagas} de ${resumo.existentes} parcelas`"
                >
                    <div
                        class="h-full rounded-full bg-primary"
                        :style="{ width: `${progresso}%` }"
                    />
                </div>
                <p class="text-sm text-muted-foreground tabular-nums">
                    {{ resumo.pagas }} de {{ resumo.existentes }}
                    {{ resumo.existentes === 1 ? 'parcela' : 'parcelas' }}
                    {{ serie.tipo === 'despesa' ? 'pagas' : 'recebidas' }}
                </p>
            </div>
        </section>

        <section
            class="overflow-hidden rounded-2xl border bg-card"
            aria-label="Parcelas"
        >
            <ul class="divide-y">
                <li
                    v-for="parcela in parcelas"
                    :key="parcela.numero"
                    class="flex items-center justify-between gap-3 p-3"
                    :class="{ 'bg-destructive/5': parcela.vencida }"
                >
                    <span class="grid min-w-0 gap-0.5">
                        <span class="flex items-baseline gap-2">
                            <span class="font-medium tabular-nums">{{
                                numeroDaParcela(parcela)
                            }}</span>
                            <span
                                class="text-sm text-muted-foreground tabular-nums"
                                >{{ dataCompleta(parcela.data) }}</span
                            >
                        </span>
                        <span
                            v-if="parcela.dataCompra"
                            class="text-xs text-muted-foreground tabular-nums"
                            >compra em
                            {{ dataCompleta(parcela.dataCompra) }}</span
                        >
                        <span
                            class="flex items-center gap-1.5 truncate text-sm text-muted-foreground"
                        >
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :class="coresCarteira[parcela.carteira.cor]"
                                aria-hidden="true"
                            />
                            {{ parcela.carteira.nome }}
                        </span>
                    </span>
                    <span class="grid shrink-0 justify-items-end gap-1">
                        <span class="font-medium tabular-nums">{{
                            formatarMoeda(parcela.valor)
                        }}</span>
                        <Badge v-if="parcela.executado" variant="secondary">{{
                            rotuloExecutado
                        }}</Badge>
                        <Badge v-else-if="parcela.vencida" variant="destructive"
                            >Vencida</Badge
                        >
                        <Badge v-else variant="outline">Pendente</Badge>
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
