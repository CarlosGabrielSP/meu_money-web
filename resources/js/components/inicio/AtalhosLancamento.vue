<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, useId } from 'vue';
import LancamentoController from '@/actions/App/Http/Controllers/LancamentoController';
import CategoriaIcone from '@/components/categorias/CategoriaIcone.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dataCompleta } from '@/lib/datas';
import { mascararValor } from '@/lib/dinheiro';
import type { AtalhoLancamento } from '@/types';

const props = defineProps<{
    atalhos: AtalhoLancamento[];
    carteiraId: number;
    carteiraNome: string;
    dataPadrao: string;
}>();

const aberto = ref(false);
const selecionado = ref<AtalhoLancamento | null>(null);
const idValor = useId();
const idDescricao = useId();
let botaoOrigem: HTMLButtonElement | null = null;

const form = useForm({
    valor: '',
    descricao: '',
});

function abrir(atalho: AtalhoLancamento, evento: MouseEvent): void {
    botaoOrigem = evento.currentTarget as HTMLButtonElement;
    selecionado.value = atalho;
    form.resetAndClearErrors();
    form.descricao = atalho.nome;
    aberto.value = true;
}

function devolverFoco(evento: Event): void {
    if (botaoOrigem?.isConnected) {
        evento.preventDefault();
        botaoOrigem.focus();
    }
}

function salvar(): void {
    const atalho = selecionado.value;

    if (atalho === null || form.processing) {
        return;
    }

    form.transform((campos) => ({
        ...campos,
        tipo: atalho.tipo,
        categoria_id: atalho.id,
        carteira_id: props.carteiraId,
        data: props.dataPadrao,
    })).submit(LancamentoController.store(), {
        preserveScroll: true,
        onSuccess: () => {
            aberto.value = false;
        },
    });
}
</script>

<template>
    <section v-if="atalhos.length" aria-label="Lançamentos rápidos">
        <ul class="grid grid-cols-5 gap-1 sm:gap-2">
            <li v-for="atalho in atalhos" :key="atalho.id" class="min-w-0">
                <button
                    type="button"
                    class="group grid w-full min-w-0 justify-items-center gap-1 rounded-lg px-0.5 py-1 text-center focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :title="`${atalho.nome} · ${atalho.tipo === 'receita' ? 'Receita' : 'Despesa'}`"
                    :aria-label="`${atalho.nome} · ${atalho.tipo === 'receita' ? 'Receita' : 'Despesa'}`"
                    @click="abrir(atalho, $event)"
                >
                    <CategoriaIcone
                        :icone="atalho.icone"
                        class="size-11 border"
                        :class="
                            atalho.tipo === 'receita'
                                ? 'border-emerald-600/15 bg-emerald-600/5 text-emerald-800/80 group-hover:bg-emerald-600/10 dark:border-emerald-400/15 dark:bg-emerald-400/5 dark:text-emerald-200/75 dark:group-hover:bg-emerald-400/10'
                                : 'border-red-600/15 bg-red-600/5 text-red-800/80 group-hover:bg-red-600/10 dark:border-red-400/15 dark:bg-red-400/5 dark:text-red-200/75 dark:group-hover:bg-red-400/10'
                        "
                    />
                    <span class="w-full truncate text-xs font-medium">
                        {{ atalho.nome }}
                    </span>
                </button>
            </li>
        </ul>

        <Dialog v-model:open="aberto">
            <DialogContent
                v-if="selecionado"
                class="sm:max-w-sm"
                @close-auto-focus="devolverFoco"
            >
                <DialogHeader>
                    <DialogTitle class="pr-6">{{
                        selecionado.nome
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            selecionado.tipo === 'receita'
                                ? 'Receita'
                                : 'Despesa'
                        }}
                        · {{ carteiraNome }} · {{ dataCompleta(dataPadrao) }}
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="salvar">
                    <div class="grid gap-2">
                        <Label :for="idValor">Valor em reais</Label>
                        <Input
                            :id="idValor"
                            :model-value="form.valor"
                            class="h-16 text-3xl font-semibold tabular-nums md:text-3xl"
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
                        <Label :for="idDescricao">Descrição</Label>
                        <Input
                            :id="idDescricao"
                            v-model="form.descricao"
                            maxlength="255"
                            required
                        />
                        <InputError :message="form.errors.descricao" />
                    </div>

                    <template
                        v-for="(mensagem, campo) in form.errors"
                        :key="campo"
                    >
                        <InputError
                            v-if="campo !== 'valor' && campo !== 'descricao'"
                            :message="mensagem"
                        />
                    </template>

                    <Button type="submit" :disabled="form.processing">
                        Salvar lançamento
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    </section>
</template>
