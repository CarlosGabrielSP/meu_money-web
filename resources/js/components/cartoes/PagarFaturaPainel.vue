<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import CampoData from '@/components/CampoData.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { mesPorExtenso } from '@/lib/datas';
import {
    centavosDe,
    formatarCentavos,
    formatarMoeda,
    mascararValor,
} from '@/lib/dinheiro';
import { pagar } from '@/routes/faturas';
import type { FaturaDoMes } from '@/types';

const props = defineProps<{
    cartaoId: number;
    cartaoNome: string;
    fatura: FaturaDoMes;
    hoje: string;
}>();

const aberto = defineModel<boolean>('open', { required: true });
const desktop = useMediaQuery('(min-width: 768px)');

/** "data" is reserved by useForm, so the date travels as "data" through transform. */
const form = useForm({ valor: '', dataPagamento: props.hoje });
const marcados = ref<number[]>([]);

/** Contacts with an open part in this invoice, the only ones the author may settle (RN-241, RN-256). */
const contatosEmAberto = computed(() =>
    props.fatura.partes.filter((parte) => !parte.voce && parte.aberto > 0),
);
const nomesMarcados = computed(() =>
    contatosEmAberto.value
        .filter((parte) => marcados.value.includes(parte.userId))
        .map((parte) => parte.nome),
);
/** Typed amount plus the marked parts (RN-241). */
const total = computed(
    () =>
        centavosDe(form.valor) +
        contatosEmAberto.value
            .filter((parte) => marcados.value.includes(parte.userId))
            .reduce((soma, parte) => soma + parte.aberto, 0),
);

function marcar(userId: number, marcado: boolean): void {
    marcados.value = marcado
        ? [...marcados.value, userId]
        : marcados.value.filter((id) => id !== userId);
}

/**
 * Suggests the user's own to pay, without the contacts' open parts, when positive, and today (R4, S3).
 */
watch(aberto, (abrindo) => {
    if (abrindo) {
        form.clearErrors();
        form.valor =
            props.fatura.aPagarAutor > 0
                ? formatarCentavos(props.fatura.aPagarAutor)
                : '';
        form.dataPagamento = props.hoje;
        marcados.value = [];
    }
});

function enviar(): void {
    form.transform(({ valor, dataPagamento }) => ({
        valor,
        data: dataPagamento,
        contatos: marcados.value,
    })).post(pagar.url([props.cartaoId, props.fatura.referencia]), {
        preserveScroll: true,
        onSuccess: () => (aberto.value = false),
    });
}
</script>

<template>
    <Sheet v-model:open="aberto">
        <SheetContent
            :side="desktop ? 'right' : 'bottom'"
            class="max-h-[85vh] gap-0 rounded-t-3xl md:max-h-none md:rounded-none"
        >
            <SheetHeader>
                <SheetTitle class="font-display text-lg">
                    Pagar fatura de
                    {{ mesPorExtenso(fatura.competencia, false) }} ({{
                        cartaoNome
                    }})
                </SheetTitle>
                <SheetDescription>
                    Sai de: {{ fatura.pagaPor ?? 'fora do app' }}
                </SheetDescription>
            </SheetHeader>
            <form class="grid gap-4 px-4 pb-6" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="valor-pagamento">Valor</Label>
                    <Input
                        id="valor-pagamento"
                        :model-value="form.valor"
                        inputmode="numeric"
                        placeholder="0,00"
                        :required="marcados.length === 0"
                        @update:model-value="
                            form.valor = mascararValor(String($event))
                        "
                    />
                    <InputError :message="form.errors.valor" />
                </div>
                <!-- Settle contacts' parts too (RN-241, RN-256, RN-264); same in the simple mode. -->
                <fieldset v-if="contatosEmAberto.length > 0" class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        Quitar também a parte de:
                    </legend>
                    <label
                        v-for="parte in contatosEmAberto"
                        :key="parte.userId"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="marcados.includes(parte.userId)"
                            @update:model-value="
                                marcar(parte.userId, $event === true)
                            "
                        />
                        <span class="flex-1">{{ parte.nome }}</span>
                        <span class="text-muted-foreground tabular-nums">
                            {{ formatarCentavos(parte.aberto) }} em aberto
                        </span>
                    </label>
                    <p class="text-sm">
                        Total do pagamento:
                        <strong class="tabular-nums">{{
                            formatarMoeda(total)
                        }}</strong>
                    </p>
                    <p
                        v-if="nomesMarcados.length > 0 && fatura.pagaPor"
                        class="text-xs text-muted-foreground"
                    >
                        Fica um "A receber de
                        {{ nomesMarcados.join(', ') }}" em {{ fatura.pagaPor }}.
                    </p>
                </fieldset>
                <div class="grid gap-2">
                    <Label for="data-pagamento">Data</Label>
                    <CampoData
                        id="data-pagamento"
                        v-model="form.dataPagamento"
                        :max="hoje"
                    />
                    <InputError
                        :message="(form.errors as Record<string, string>).data"
                    />
                </div>
                <Button type="submit" :disabled="form.processing">Pagar</Button>
            </form>
        </SheetContent>
    </Sheet>
</template>
