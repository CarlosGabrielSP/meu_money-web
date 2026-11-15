<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { encerrar, total } from '@/routes/parcelamentos';
import type { SerieDaParcela } from '@/types';

const props = defineProps<{
    serie: SerieDaParcela;
    hoje: string;
}>();

const emit = defineEmits<{
    concluido: [];
}>();

const page = usePage();
const encerrando = ref(false);
const alterandoTotal = ref(false);
const dataEncerramento = ref(props.hoje);
const novoTotal = ref(props.serie.total ?? 2);

function confirmarEncerramento(): void {
    router.post(
        encerrar.url(props.serie.id),
        { data: dataEncerramento.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                encerrando.value = false;
                emit('concluido');
            },
        },
    );
}

function confirmarTotal(): void {
    router.patch(
        total.url(props.serie.id),
        { total_parcelas: novoTotal.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                alterandoTotal.value = false;
                emit('concluido');
            },
        },
    );
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-muted-foreground">
            <template v-if="serie.recorrente">
                Parcela {{ serie.numero }} de série recorrente
                <template v-if="serie.encerradaEm">
                    (encerrada em
                    {{ serie.encerradaEm.split('-').reverse().join('/') }})
                </template>
            </template>
            <template v-else>
                Parcela {{ serie.numero }}/{{ serie.total }}
            </template>
        </span>
        <Button
            v-if="serie.recorrente && !serie.encerradaEm"
            type="button"
            variant="outline"
            size="sm"
            @click="encerrando = true"
        >
            Encerrar série
        </Button>
        <Button
            v-if="!serie.recorrente"
            type="button"
            variant="outline"
            size="sm"
            @click="alterandoTotal = true"
        >
            Alterar total
        </Button>

        <Dialog v-model:open="encerrando">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Encerrar série</DialogTitle>
                    <DialogDescription>
                        Parcelas pendentes depois desta data serão removidas.
                        Parcelas executadas ficam.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="data_encerramento">Encerrar em</Label>
                    <Input
                        id="data_encerramento"
                        v-model="dataEncerramento"
                        type="date"
                    />
                    <InputError :message="page.props.errors.data" />
                </div>
                <DialogFooter>
                    <Button
                        variant="destructive"
                        @click="confirmarEncerramento"
                    >
                        Encerrar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="alterandoTotal">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Alterar total de parcelas</DialogTitle>
                    <DialogDescription>
                        Aumentar cria parcelas no fim. Reduzir remove parcelas
                        pendentes do fim.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="novo_total">Total de parcelas</Label>
                    <Input
                        id="novo_total"
                        v-model.number="novoTotal"
                        type="number"
                        min="2"
                        max="360"
                    />
                    <InputError :message="page.props.errors.total_parcelas" />
                </div>
                <DialogFooter>
                    <Button @click="confirmarTotal">Salvar</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
