<script setup lang="ts">
import CampoData from '@/components/CampoData.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
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
import { encerrar, show, total } from '@/routes/parcelamentos';
import type { SerieDaParcela, TipoLancamento } from '@/types';
import { dataCompleta } from '@/lib/datas';

const props = defineProps<{
    serie: SerieDaParcela;
    /** Names the executed installments "pagas" or "recebidas" (DEC-093). */
    tipo: TipoLancamento;
    hoje: string;
    /** False for an author without a share in the open installment (RN-087), who gets no link (RN-213). */
    comCota: boolean;
    /** Unsaved changes in the form disable the link, so leaving does not drop them (RN-213). */
    alteracoesPendentes: boolean;
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
                    {{ dataCompleta(serie.encerradaEm) }})
                </template>
            </template>
            <template v-else>
                Parcela {{ serie.numero }}/{{ serie.total }}
            </template>
        </span>
        <template v-if="comCota">
            <Button
                v-if="alteracoesPendentes"
                type="button"
                variant="outline"
                size="sm"
                disabled
                aria-describedby="ver-parcelas-dica"
            >
                Ver parcelas
            </Button>
            <Button v-else variant="outline" size="sm" as-child>
                <Link :href="show(serie.id)">Ver parcelas</Link>
            </Button>
            <span
                v-if="alteracoesPendentes"
                id="ver-parcelas-dica"
                class="text-xs text-muted-foreground"
                >Salve antes de ver as parcelas</span
            >
        </template>
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
                        Parcelas
                        {{ tipo === 'receita' ? 'recebidas' : 'pagas' }} ficam.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="data_encerramento">Encerrar em</Label>
                    <CampoData
                        id="data_encerramento"
                        v-model="dataEncerramento"
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
