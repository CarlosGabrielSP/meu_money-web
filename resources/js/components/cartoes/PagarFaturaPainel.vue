<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { watch } from 'vue';
import CampoData from '@/components/CampoData.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import { formatarCentavos, mascararValor } from '@/lib/dinheiro';
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

/**
 * Suggests what is left to pay when positive, and today (R4).
 */
watch(aberto, (abrindo) => {
    if (abrindo) {
        form.clearErrors();
        form.valor =
            props.fatura.aPagar > 0
                ? formatarCentavos(props.fatura.aPagar)
                : '';
        form.dataPagamento = props.hoje;
    }
});

function enviar(): void {
    form.transform(({ valor, dataPagamento }) => ({
        valor,
        data: dataPagamento,
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
                    {{ mesPorExtenso(fatura.referencia, false) }} ({{
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
                        required
                        @update:model-value="
                            form.valor = mascararValor(String($event))
                        "
                    />
                    <InputError :message="form.errors.valor" />
                </div>
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
