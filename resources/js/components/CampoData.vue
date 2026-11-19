<script setup lang="ts">
import { CalendarDate, parseDate, today } from '@internationalized/date';
import type { DateValue } from '@internationalized/date';
import { CalendarDays, ChevronLeft, ChevronRight } from '@lucide/vue';
import {
    CalendarCell,
    CalendarCellTrigger,
    CalendarGrid,
    CalendarGridBody,
    CalendarGridHead,
    CalendarGridRow,
    CalendarHeadCell,
    CalendarNext,
    CalendarPrev,
    CalendarRoot,
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, ref, shallowRef, useId, watch } from 'vue';
import type { HTMLAttributes } from 'vue';
import CampoSelecao from '@/components/CampoSelecao.vue';
import { Button } from '@/components/ui/button';
import { dataCompleta } from '@/lib/datas';
import { cn } from '@/lib/utils';

/**
 * Date field of the UI library in place of the native date input (DEC-079): a button with the date
 * (dd/mm/aaaa) that opens a calendar with month and year lists, to reach old dates such as a birth
 * date quickly. The value is "Y-m-d" or null, like the native input; `name` sends it in plain forms.
 */
const props = defineProps<{
    modelValue?: string | null;
    /** Initial value of a form without v-model. */
    defaultValue?: string | null;
    id?: string;
    name?: string;
    min?: string;
    max?: string;
    placeholder?: string;
    /** Optional date: the calendar offers "Limpar" to leave it empty. */
    limpavel?: boolean;
    /** Read only, for a purchase in a closed invoice (nota 07). */
    disabled?: boolean;
    class?: HTMLAttributes['class'];
}>();

const emit = defineEmits<{
    'update:modelValue': [valor: string | null];
}>();

const fusoHorario = 'America/Sao_Paulo';
/** The visible label names the button, so the chosen date goes in its description (RNF-008). */
const idDoValor = `${useId()}-valor`;
const aberto = ref(false);
const valor = ref<string | null>(
    props.modelValue ?? props.defaultValue ?? null,
);

watch(
    () => props.modelValue,
    (novo) => {
        if (novo !== undefined) {
            valor.value = novo;
        }
    },
);

function paraData(texto: string | null | undefined): CalendarDate | undefined {
    if (!texto || !/^\d{4}-\d{2}-\d{2}$/.test(texto)) {
        return undefined;
    }

    try {
        return parseDate(texto);
    } catch {
        return undefined;
    }
}

const minimo = computed(() => paraData(props.min));
const maximo = computed(() => paraData(props.max));
const selecionada = computed(() => paraData(valor.value));
const hoje = today(fusoHorario);

/** Month shown by the calendar: the chosen date, else today within the limits. */
const vista = shallowRef<DateValue>(inicioDaVista());

function inicioDaVista(): DateValue {
    if (selecionada.value) {
        return selecionada.value;
    }

    if (maximo.value && hoje.compare(maximo.value) > 0) {
        return maximo.value;
    }

    if (minimo.value && hoje.compare(minimo.value) < 0) {
        return minimo.value;
    }

    return hoje;
}

watch(aberto, (estaAberto) => {
    if (estaAberto) {
        vista.value = inicioDaVista();
    }
});

const anos = computed(() => {
    const escolhido = selecionada.value?.year ?? hoje.year;
    const primeiro =
        minimo.value?.year ??
        Math.min(hoje.year, escolhido, vista.value.year) - 10;
    const ultimo =
        maximo.value?.year ??
        Math.max(hoje.year, escolhido, vista.value.year) + 10;

    return Array.from({ length: ultimo - primeiro + 1 }, (_, indice) => {
        const ano = ultimo - indice;

        return { valor: ano, rotulo: String(ano) };
    });
});

const meses = Array.from({ length: 12 }, (_, indice) => {
    const nome = new Intl.DateTimeFormat('pt-BR', { month: 'long' }).format(
        new Date(2000, indice, 1),
    );

    return {
        valor: indice + 1,
        rotulo: `${nome.charAt(0).toUpperCase()}${nome.slice(1)}`,
    };
});

function irPara(mes: number | null, ano: number | null): void {
    if (mes !== null && ano !== null) {
        vista.value = new CalendarDate(ano, mes, 1);
    }
}

function escolher(data: DateValue | undefined): void {
    const texto = data ? data.toString() : null;

    valor.value = texto;
    emit('update:modelValue', texto);
    aberto.value = false;
}
</script>

<template>
    <PopoverRoot v-model:open="aberto">
        <PopoverTrigger as-child :disabled="disabled">
            <Button
                :id="id"
                type="button"
                variant="outline"
                :disabled="disabled"
                :aria-describedby="idDoValor"
                :class="
                    cn(
                        'w-full justify-start font-normal',
                        !valor && 'text-muted-foreground',
                        props.class,
                    )
                "
            >
                <CalendarDays />
                <span :id="idDoValor">{{
                    valor
                        ? dataCompleta(valor)
                        : (placeholder ?? 'Escolher data')
                }}</span>
            </Button>
        </PopoverTrigger>
        <input v-if="name" type="hidden" :name="name" :value="valor ?? ''" />
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="4"
                class="z-50 w-72 rounded-xl border bg-popover p-3 text-popover-foreground shadow-md outline-none simples:w-auto simples:p-2"
            >
                <CalendarRoot
                    v-slot="{ grid, weekDays }"
                    :model-value="selecionada"
                    v-model:placeholder="vista"
                    :min-value="minimo"
                    :max-value="maximo"
                    locale="pt-BR"
                    weekday-format="short"
                    fixed-weeks
                    @update:model-value="escolher"
                >
                    <div class="mb-3 flex items-center gap-1">
                        <CalendarPrev as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-8 shrink-0"
                                aria-label="Mês anterior"
                            >
                                <ChevronLeft />
                            </Button>
                        </CalendarPrev>
                        <CampoSelecao
                            :model-value="vista.month"
                            :opcoes="meses"
                            rotulo="Mês"
                            class="h-8 flex-1 px-2"
                            @update:model-value="
                                (mes) => irPara(mes, vista.year)
                            "
                        />
                        <CampoSelecao
                            :model-value="vista.year"
                            :opcoes="anos"
                            rotulo="Ano"
                            class="h-8 w-[5.5rem] shrink-0 px-2"
                            @update:model-value="
                                (ano) => irPara(vista.month, ano)
                            "
                        />
                        <CalendarNext as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-8 shrink-0"
                                aria-label="Próximo mês"
                            >
                                <ChevronRight />
                            </Button>
                        </CalendarNext>
                    </div>
                    <CalendarGrid
                        v-for="mes in grid"
                        :key="mes.value.toString()"
                        class="w-full border-collapse"
                    >
                        <CalendarGridHead>
                            <CalendarGridRow class="grid grid-cols-7">
                                <CalendarHeadCell
                                    v-for="dia in weekDays"
                                    :key="dia"
                                    class="py-1 text-center text-xs font-normal text-muted-foreground"
                                >
                                    {{ dia }}
                                </CalendarHeadCell>
                            </CalendarGridRow>
                        </CalendarGridHead>
                        <CalendarGridBody>
                            <CalendarGridRow
                                v-for="(semana, indice) in mes.rows"
                                :key="indice"
                                class="grid grid-cols-7"
                            >
                                <CalendarCell
                                    v-for="dia in semana"
                                    :key="dia.toString()"
                                    :date="dia"
                                    class="p-0.5 text-center simples:p-0"
                                >
                                    <CalendarCellTrigger
                                        :day="dia"
                                        :month="mes.value"
                                        class="inline-flex size-9 items-center justify-center rounded-md text-sm tabular-nums outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring data-disabled:pointer-events-none data-disabled:opacity-40 data-outside-view:text-muted-foreground/50 data-selected:bg-primary data-selected:text-primary-foreground data-today:font-semibold data-today:underline data-today:underline-offset-4 data-unavailable:line-through simples:size-[48px]"
                                    />
                                </CalendarCell>
                            </CalendarGridRow>
                        </CalendarGridBody>
                    </CalendarGrid>
                </CalendarRoot>
                <Button
                    v-if="limpavel && valor"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="mt-2 w-full"
                    @click="escolher(undefined)"
                >
                    Limpar
                </Button>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
