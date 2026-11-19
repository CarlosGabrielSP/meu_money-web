<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import CampoData from '@/components/CampoData.vue';
import InputError from '@/components/InputError.vue';
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
import { mesPorExtenso } from '@/lib/datas';
import { datas } from '@/routes/faturas';
import type { FaturaDoMes } from '@/types';

const props = defineProps<{
    cartaoId: number;
    fatura: FaturaDoMes;
    hoje: string;
}>();

/** Last day of the invoice month, the latest due date (RN-193). */
const fimDoMes = computed(() => {
    const [ano, mes] = props.fatura.referencia.split('-').map(Number);

    return new Date(Date.UTC(ano, mes, 0)).toISOString().slice(0, 10);
});

const aberto = defineModel<boolean>('open', { required: true });

const form = useForm({
    fechamento: props.fatura.fechamento,
    vencimento: props.fatura.vencimento,
});

watch(aberto, (abrindo) => {
    if (abrindo) {
        form.clearErrors();
        form.fechamento = props.fatura.fechamento;
        form.vencimento = props.fatura.vencimento;
    }
});

/**
 * Closing and due date of an open or future invoice (RN-193); the server checks the limits.
 */
function salvar(): void {
    form.put(datas.url([props.cartaoId, props.fatura.referencia]), {
        preserveScroll: true,
        onSuccess: () => (aberto.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="aberto">
        <DialogContent>
            <form class="grid gap-4" @submit.prevent="salvar">
                <DialogHeader>
                    <DialogTitle>
                        Datas da fatura de
                        {{ mesPorExtenso(fatura.referencia, false) }}
                    </DialogTitle>
                    <DialogDescription>
                        Compras depois do fechamento passam para a próxima
                        fatura.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="fechamento-fatura">Fechamento</Label>
                    <CampoData
                        id="fechamento-fatura"
                        v-model="form.fechamento"
                        :min="hoje"
                    />
                    <InputError :message="form.errors.fechamento" />
                </div>
                <div class="grid gap-2">
                    <Label for="vencimento-fatura">Vencimento</Label>
                    <CampoData
                        id="vencimento-fatura"
                        v-model="form.vencimento"
                        :min="form.fechamento"
                        :max="fimDoMes"
                    />
                    <InputError :message="form.errors.vencimento" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Salvar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
