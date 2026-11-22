<script setup lang="ts">
import { Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
import DatasFaturaDialog from '@/components/cartoes/DatasFaturaDialog.vue';
import PagarFaturaPainel from '@/components/cartoes/PagarFaturaPainel.vue';
import { Button } from '@/components/ui/button';
import { diaEMes, mesPorExtenso } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import type { FaturaDoMes } from '@/types';

const props = defineProps<{
    cartaoId: number;
    cartaoNome: string;
    autorDoEspelho: string | null;
    fatura: FaturaDoMes;
    hoje: string;
    simples: boolean;
    /** A member of the card pays only an invoice already stored (RN-316, RN-333). */
    membro?: boolean;
}>();

const pagando = ref(false);
const editandoDatas = ref(false);

const estados = {
    aberta: { rotulo: 'Aberta', classe: 'bg-emerald-200 text-emerald-950' },
    fechada: { rotulo: 'Fechada', classe: 'bg-amber-200 text-amber-950' },
    futura: { rotulo: 'Futura', classe: 'bg-slate-200 text-slate-950' },
};

const compras = computed(
    () => props.fatura.compras + props.fatura.comprasContatos,
);
const receitas = computed(
    () => props.fatura.receitas + props.fatura.receitasContatos,
);
const totalDaFatura = computed(() => compras.value - receitas.value);

/** Balance of this cycle with the existing payment allocations (RN-343). */
const saldoSoDestaFatura = computed(
    () => props.fatura.aPagar - props.fatura.saldoAnterior,
);

/** A negative "to pay" is a credit (nota 07). */
const credito = computed(() => props.fatura.aPagar < 0);
</script>

<template>
    <!-- Invoice card of the card screen (RN-198, RN-223), in place of the balances card. Purchases and
         income are the invoice totals, with the contacts' parts (RN-239, CA-183.1). -->
    <section
        class="rounded-3xl bg-saldo p-5 text-saldo-foreground shadow-sm md:p-6 dark:border dark:border-saldo-borda"
        aria-label="Fatura"
        data-tutorial="saldos"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2"
        >
            <p class="text-sm text-saldo-foreground/75">
                Fatura de {{ mesPorExtenso(fatura.competencia, false) }}
            </p>
            <div class="ml-auto flex items-center gap-2">
                <Button
                    v-if="fatura.aPagar > 0 && !(membro && fatura.id === null)"
                    size="sm"
                    class="bg-saldo-foreground text-saldo hover:bg-saldo-foreground/90"
                    data-test="pagar-fatura"
                    @click="pagando = true"
                >
                    Pagar
                </Button>
                <span
                    v-if="!simples"
                    class="rounded-full px-2.5 py-1 text-xs font-semibold"
                    :class="estados[fatura.estado].classe"
                >
                    {{ estados[fatura.estado].rotulo }}
                </span>
            </div>
        </div>
        <!-- The contact's mirror names the card and its author (RN-246, nota 07). -->
        <p v-if="autorDoEspelho" class="mt-1 text-sm font-medium">
            {{ cartaoNome }}
            <span class="font-normal text-saldo-foreground/75"
                >de {{ autorDoEspelho }}</span
            >
        </p>
        <p class="mt-3 text-sm text-saldo-foreground/75">Total da fatura</p>
        <p
            class="mt-1 font-display text-[2.5rem] leading-none font-semibold tracking-tight tabular-nums md:text-5xl"
        >
            {{ formatarMoeda(totalDaFatura) }}
        </p>
        <dl
            class="mt-2 grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-3 gap-y-1 text-sm"
        >
            <dt class="text-saldo-foreground/75">
                {{ credito ? 'Crédito acumulado' : 'A pagar acumulado' }}
            </dt>
            <dd class="text-right font-semibold tabular-nums">
                {{ formatarMoeda(Math.abs(fatura.aPagar)) }}
            </dd>
            <dt class="text-saldo-foreground/75">
                Só esta fatura · sem saldo anterior
            </dt>
            <dd class="text-right font-semibold tabular-nums">
                {{ formatarMoeda(saldoSoDestaFatura) }}
            </dd>
        </dl>

        <p class="mt-4 flex items-center gap-2 text-sm">
            <template v-if="!simples">
                Fecha {{ diaEMes(fatura.fechamento) }} ·
            </template>
            Vence {{ diaEMes(fatura.vencimento) }}
            <Button
                v-if="!simples && fatura.editavel"
                variant="ghost"
                size="icon"
                class="size-8 text-saldo-foreground hover:bg-saldo-foreground/10 hover:text-saldo-foreground"
                aria-label="Editar datas da fatura"
                @click="editandoDatas = true"
            >
                <Pencil class="size-4" />
            </Button>
        </p>

        <template v-if="!simples">
            <p class="text-sm text-saldo-foreground/75">
                Compras de {{ diaEMes(fatura.inicio) }} a
                {{ diaEMes(fatura.fechamento) }}
            </p>
            <dl
                class="mt-4 grid grid-cols-2 gap-3 border-t border-saldo-foreground/15 pt-4 text-sm sm:grid-cols-4"
            >
                <div class="grid gap-0.5">
                    <dt class="text-saldo-foreground/75">Saldo anterior</dt>
                    <dd class="font-medium tabular-nums">
                        {{ formatarMoeda(fatura.saldoAnterior) }}
                    </dd>
                </div>
                <div class="grid gap-0.5">
                    <dt class="text-saldo-foreground/75">Compras</dt>
                    <dd class="font-medium text-red-300 tabular-nums">
                        {{ formatarMoeda(compras) }}
                    </dd>
                </div>
                <div class="grid gap-0.5">
                    <dt class="text-saldo-foreground/75">Receitas</dt>
                    <dd class="font-medium text-emerald-300 tabular-nums">
                        {{ formatarMoeda(receitas) }}
                    </dd>
                </div>
                <div class="grid gap-0.5">
                    <dt class="text-saldo-foreground/75">Pagamentos</dt>
                    <dd class="font-medium tabular-nums">
                        {{ formatarMoeda(fatura.pagamentos) }}
                    </dd>
                </div>
            </dl>
        </template>
    </section>

    <PagarFaturaPainel
        v-model:open="pagando"
        :cartao-id="cartaoId"
        :cartao-nome="cartaoNome"
        :fatura="fatura"
        :hoje="hoje"
    />
    <DatasFaturaDialog
        v-if="fatura.editavel"
        v-model:open="editandoDatas"
        :cartao-id="cartaoId"
        :fatura="fatura"
        :hoje="hoje"
    />
</template>
