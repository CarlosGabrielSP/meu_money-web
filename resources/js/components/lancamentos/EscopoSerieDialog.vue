<script setup lang="ts">
import { ref, watch } from 'vue';
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
import type { EscopoSerie, TipoLancamento } from '@/types';

const aberto = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    acao: 'editar' | 'excluir' | 'sair' | 'desagrupar';
    /** Names the executed installments "pagas" or "recebidas" (DEC-093). */
    tipo?: TipoLancamento;
    erro?: string;
    processando?: boolean;
}>();

const emit = defineEmits<{
    confirmar: [escopo: EscopoSerie];
}>();

const escopo = ref<EscopoSerie>('esta');

watch(aberto, (estaAberto) => {
    if (estaAberto) {
        escopo.value = 'esta';
    }
});

const titulos = {
    editar: 'Alterar quais parcelas?',
    excluir: 'Excluir quais parcelas?',
    sair: 'Sair da divisão em quais parcelas?',
    desagrupar: 'Desagrupar quais parcelas?',
};

const opcoes: { valor: EscopoSerie; titulo: string }[] = [
    { valor: 'esta', titulo: 'Só esta parcela' },
    { valor: 'proximas', titulo: 'Esta e as próximas' },
    { valor: 'todas', titulo: 'Todas as parcelas' },
];
</script>

<template>
    <Dialog v-model:open="aberto">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ titulos[props.acao] }}</DialogTitle>
                <DialogDescription>
                    <template v-if="props.acao === 'desagrupar'">
                        Nas opções em massa, saem as parcelas do mesmo grupo e
                        as futuras deixam de entrar nele.
                    </template>
                    <template v-else>
                        Parcelas já
                        {{ props.tipo === 'receita' ? 'recebidas' : 'pagas' }}
                        não mudam nas opções em massa.
                    </template>
                </DialogDescription>
            </DialogHeader>
            <fieldset class="grid gap-2">
                <legend class="sr-only">Escopo</legend>
                <label
                    v-for="opcao in opcoes"
                    :key="opcao.valor"
                    class="flex items-center gap-2"
                >
                    <input v-model="escopo" type="radio" :value="opcao.valor" />
                    {{ opcao.titulo }}
                </label>
            </fieldset>
            <InputError :message="erro" />
            <DialogFooter>
                <Button
                    :variant="
                        props.acao === 'excluir' ? 'destructive' : 'default'
                    "
                    :disabled="processando"
                    @click="emit('confirmar', escopo)"
                >
                    {{
                        {
                            editar: 'Salvar',
                            excluir: 'Excluir',
                            sair: 'Sair',
                            desagrupar: 'Desagrupar',
                        }[props.acao]
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
