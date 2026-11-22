<script setup lang="ts">
import { computed } from 'vue';
import { formatarMoeda } from '@/lib/dinheiro';
import type { SaldosInicio, VisaoSaldos } from '@/types';

const props = defineProps<{
    saldos: SaldosInicio;
    simples: boolean;
}>();

const visao = defineModel<VisaoSaldos>({ required: true });

const previsto = computed(() => props.saldos.acumulado.estimado);
const pendente = computed(() => props.saldos.aReceber + props.saldos.aPagar);

/**
 * Share of each pending part on the track; at least 4% so a small amount stays visible (RN-169).
 */
function largura(valor: number): string {
    return `${Math.max((valor / pendente.value) * 100, 4)}%`;
}

function comSinal(valor: number): string {
    return `${valor > 0 ? '+' : valor < 0 ? '−' : ''} ${formatarMoeda(Math.abs(valor))}`;
}
</script>

<template>
    <!-- Main card (RN-092, RN-169): the only strong element of the screen, in the side menu color
         (DEC-070). The simple mode shows the balances of RN-160, without the track (RN-175). -->
    <section
        class="rounded-3xl bg-saldo p-5 text-saldo-foreground shadow-sm md:p-6 dark:border dark:border-saldo-borda"
        aria-label="Saldos"
        data-tutorial="saldos"
    >
        <div
            role="group"
            aria-label="Visão dos saldos"
            class="mb-4 inline-flex max-w-full flex-wrap gap-1 rounded-lg bg-black/15 p-1 text-sm"
        >
            <button
                type="button"
                :aria-pressed="visao === 'acumulado'"
                class="rounded-md px-3 py-2 aria-pressed:bg-saldo-foreground aria-pressed:text-saldo"
                @click="visao = 'acumulado'"
            >
                Acumulado
            </button>
            <button
                type="button"
                :aria-pressed="visao === 'mes'"
                class="rounded-md px-3 py-2 aria-pressed:bg-saldo-foreground aria-pressed:text-saldo"
                @click="visao = 'mes'"
            >
                Resultado do mês
            </button>
        </div>

        <div v-if="visao === 'mes' || simples" class="@container grid gap-1">
            <p class="text-sm text-saldo-foreground/75">
                {{ visao === 'mes' ? 'Resultado do mês' : 'Saldo atual' }}
            </p>
            <p
                class="font-display text-[clamp(1.5rem,7.5cqi,3rem)] leading-none font-semibold tracking-tight tabular-nums"
            >
                {{
                    formatarMoeda(
                        visao === 'mes'
                            ? saldos.mes.estimado
                            : saldos.acumulado.atual,
                    )
                }}
            </p>
        </div>

        <!-- Full mode: current balance on the left and the balance foreseen for the end of the month
             on the right, both large, at the two ends of the track (DEC-077). The size follows the
             card width, and the foreseen one wraps below when both do not fit. -->
        <div
            v-else
            class="@container flex flex-wrap items-end justify-between gap-x-4 gap-y-3"
        >
            <p class="grid gap-1">
                <span class="text-sm text-saldo-foreground/75"
                    >Saldo atual</span
                >
                <span
                    class="font-display text-[clamp(1.5rem,7.5cqi,3rem)] leading-none font-semibold tracking-tight tabular-nums"
                    >{{ formatarMoeda(saldos.acumulado.atual) }}</span
                >
            </p>
            <p class="ml-auto grid gap-1 text-right">
                <span class="text-sm text-saldo-foreground/75"
                    >Saldo estimado</span
                >
                <span
                    class="font-display text-[clamp(1.5rem,7.5cqi,3rem)] leading-none font-semibold tracking-tight tabular-nums"
                    >{{ formatarMoeda(previsto) }}</span
                >
            </p>
        </div>

        <template v-if="!simples && visao === 'acumulado'">
            <!-- Month track: from the current balance to the balance foreseen for the end of the
                 month; dashed parts are what is still to receive and to pay (RN-169). -->
            <div class="mt-4" aria-hidden="true">
                <div class="flex items-center gap-1.5">
                    <span
                        class="size-3 shrink-0 rounded-full bg-saldo-foreground"
                    />
                    <span
                        v-if="pendente === 0"
                        class="h-0.5 flex-1 rounded-full bg-saldo-foreground/40"
                    />
                    <span v-else class="flex flex-1 items-center gap-1">
                        <span
                            v-if="saldos.aReceber > 0"
                            class="h-0 border-t-2 border-dashed border-emerald-300"
                            :style="{ width: largura(saldos.aReceber) }"
                        />
                        <span
                            v-if="saldos.aPagar > 0"
                            class="h-0 border-t-2 border-dashed border-red-300"
                            :style="{ width: largura(saldos.aPagar) }"
                        />
                    </span>
                    <span
                        class="size-3 shrink-0 rounded-full border-2 border-saldo-foreground"
                    />
                </div>
            </div>
            <p
                class="mt-3 flex flex-wrap justify-between gap-x-4 gap-y-1 text-sm"
            >
                <span class="text-emerald-300 tabular-nums"
                    >{{ comSinal(saldos.aReceber) }} a receber</span
                >
                <span class="text-red-300 tabular-nums"
                    >{{ comSinal(-saldos.aPagar) }} a pagar</span
                >
            </p>

            <p class="mt-1 text-sm text-saldo-foreground/75">
                Até o fim do mês selecionado
            </p>
        </template>

        <template v-if="visao === 'mes'">
            <dl
                v-if="!simples"
                class="mt-4 flex flex-wrap gap-x-6 gap-y-3 text-sm"
            >
                <div class="grid gap-0.5">
                    <dt class="text-saldo-foreground/75">Receitas</dt>
                    <dd class="font-medium text-emerald-300 tabular-nums">
                        {{ formatarMoeda(saldos.receitas) }}
                    </dd>
                </div>
                <div class="grid gap-0.5">
                    <dt class="text-saldo-foreground/75">Despesas</dt>
                    <dd class="font-medium text-red-300 tabular-nums">
                        {{ formatarMoeda(saldos.despesas) }}
                    </dd>
                </div>
                <div class="grid gap-0.5">
                    <dt class="text-saldo-foreground/75">
                        Movimentação realizada no mês
                    </dt>
                    <dd class="font-medium tabular-nums">
                        {{ formatarMoeda(saldos.mes.atual) }}
                    </dd>
                </div>
            </dl>
            <p class="mt-3 text-sm text-saldo-foreground/75">
                Receitas e despesas incluem lançamentos pendentes.
            </p>
        </template>
    </section>
</template>
