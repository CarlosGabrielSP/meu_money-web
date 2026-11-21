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
    /** Until stage 17.3 a member of the card does not pay the invoice (RN-329). */
    membro?: boolean;
}>();

const pagando = ref(false);
const editandoDatas = ref(false);

const estados = { fechada: 'Fechada', aberta: 'Aberta', futura: 'Futura' };

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
        <div class="flex items-center justify-between gap-3">
            <p class="text-sm text-saldo-foreground/75">
                Fatura de {{ mesPorExtenso(fatura.competencia, false) }}
            </p>
            <span
                v-if="!simples"
                class="rounded-full border border-saldo-foreground/30 px-2.5 py-0.5 text-xs"
                >{{ estados[fatura.estado] }}</span
            >
        </div>
        <!-- The contact's mirror names the card and its author (RN-246, nota 07). -->
        <p v-if="autorDoEspelho" class="mt-1 text-sm font-medium">
            {{ cartaoNome }}
            <span class="font-normal text-saldo-foreground/75"
                >de {{ autorDoEspelho }}</span
            >
        </p>
        <p class="mt-3 text-sm text-saldo-foreground/75">
            {{ credito ? 'Crédito' : 'A pagar' }}
        </p>
        <p
            class="mt-1 font-display text-[2.5rem] leading-none font-semibold tracking-tight tabular-nums md:text-5xl"
        >
            {{ formatarMoeda(Math.abs(fatura.aPagar)) }}
        </p>

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
                        {{
                            formatarMoeda(
                                fatura.compras + fatura.comprasContatos,
                            )
                        }}
                    </dd>
                </div>
                <div class="grid gap-0.5">
                    <dt class="text-saldo-foreground/75">Receitas</dt>
                    <dd class="font-medium text-emerald-300 tabular-nums">
                        {{
                            formatarMoeda(
                                fatura.receitas + fatura.receitasContatos,
                            )
                        }}
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

        <!-- Only with something left to pay (RN-223); not for a member until stage 17.3 (RN-329). -->
        <Button
            v-if="fatura.aPagar > 0 && !membro"
            class="mt-5 w-full bg-saldo-foreground text-saldo hover:bg-saldo-foreground/90"
            data-test="pagar-fatura"
            @click="pagando = true"
        >
            Pagar
        </Button>
    </section>

    <PagarFaturaPainel
        v-if="!membro"
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
