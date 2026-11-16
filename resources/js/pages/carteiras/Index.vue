<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CarteiraController from '@/actions/App/Http/Controllers/CarteiraController';
import CarteiraCampos from '@/components/carteiras/CarteiraCampos.vue';
import CarteiraIcone from '@/components/carteiras/CarteiraIcone.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
import { index, padrao } from '@/routes/carteiras';

type Carteira = {
    id: number;
    nome: string;
    padrao: boolean;
    cor: string;
    icone: string;
    temConteudo: boolean;
};

const props = defineProps<{
    carteiras: Carteira[];
    cores: string[];
    icones: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Carteiras', href: index() }],
    },
});

const criando = ref(false);
const editando = ref<Carteira | null>(null);
const excluindo = ref<Carteira | null>(null);
const modoExclusao = ref<'mover' | 'excluir'>('mover');

const destinosPossiveis = computed(() =>
    props.carteiras.filter((carteira) => carteira.id !== excluindo.value?.id),
);

function abrirExclusao(carteira: Carteira): void {
    modoExclusao.value = carteira.temConteudo ? 'mover' : 'excluir';
    excluindo.value = carteira;
}

function tornarPadrao(carteira: Carteira): void {
    router.post(padrao(carteira.id).url, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Carteiras" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Carteiras"
                description="Organize seus lançamentos por conta, cartão ou dinheiro"
            />
            <Button data-test="nova-carteira-button" @click="criando = true">
                Nova carteira
            </Button>
        </div>

        <ul class="divide-y overflow-hidden rounded-2xl border bg-card">
            <li
                v-for="carteira in carteiras"
                :key="carteira.id"
                class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <CarteiraIcone
                        :cor="carteira.cor"
                        :icone="carteira.icone"
                    />
                    <p class="truncate font-medium">{{ carteira.nome }}</p>
                    <Badge v-if="carteira.padrao" variant="secondary"
                        >Padrão</Badge
                    >
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="!carteira.padrao"
                        variant="outline"
                        size="sm"
                        @click="tornarPadrao(carteira)"
                    >
                        Tornar padrão
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="editando = carteira"
                    >
                        Editar
                    </Button>
                    <Button
                        v-if="!carteira.padrao"
                        variant="destructive"
                        size="sm"
                        @click="abrirExclusao(carteira)"
                    >
                        Excluir
                    </Button>
                </div>
            </li>
        </ul>
    </div>

    <Dialog :open="criando" @update:open="criando = $event">
        <DialogContent>
            <Form
                v-bind="CarteiraController.store.form()"
                class="space-y-6"
                @success="criando = false"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Nova carteira</DialogTitle>
                </DialogHeader>
                <CarteiraCampos
                    :cores="cores"
                    :icones="icones"
                    :errors="errors"
                />
                <DialogFooter>
                    <Button :disabled="processing">Criar carteira</Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="editando !== null"
        @update:open="editando = $event ? editando : null"
    >
        <DialogContent v-if="editando">
            <Form
                v-bind="CarteiraController.update.form(editando.id)"
                class="space-y-6"
                @success="editando = null"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Editar carteira</DialogTitle>
                </DialogHeader>
                <CarteiraCampos
                    :cores="cores"
                    :icones="icones"
                    :errors="errors"
                    :nome="editando.nome"
                    :cor="editando.cor"
                    :icone="editando.icone"
                />
                <DialogFooter>
                    <Button :disabled="processing">Salvar</Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="excluindo !== null"
        @update:open="excluindo = $event ? excluindo : null"
    >
        <DialogContent v-if="excluindo">
            <Form
                v-bind="CarteiraController.destroy.form(excluindo.id)"
                class="space-y-6"
                @success="excluindo = null"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Excluir {{ excluindo.nome }}?</DialogTitle>
                    <DialogDescription v-if="!excluindo.temConteudo">
                        A carteira está vazia.
                    </DialogDescription>
                    <DialogDescription v-else>
                        A carteira tem lançamentos. Escolha o que fazer com
                        eles.
                    </DialogDescription>
                </DialogHeader>

                <input type="hidden" name="modo" :value="modoExclusao" />

                <div v-if="excluindo.temConteudo" class="grid gap-4">
                    <label class="flex items-center gap-2">
                        <input
                            v-model="modoExclusao"
                            type="radio"
                            value="mover"
                        />
                        Mover os lançamentos para outra carteira
                    </label>
                    <div
                        v-if="modoExclusao === 'mover'"
                        class="grid gap-2 pl-6"
                    >
                        <Label for="destino_id">Carteira de destino</Label>
                        <select
                            id="destino_id"
                            name="destino_id"
                            class="h-9 rounded-md border bg-transparent px-3 text-sm"
                        >
                            <option
                                v-for="destino in destinosPossiveis"
                                :key="destino.id"
                                :value="destino.id"
                            >
                                {{ destino.nome }}
                            </option>
                        </select>
                        <InputError :message="errors.destino_id" />
                    </div>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="modoExclusao"
                            type="radio"
                            value="excluir"
                        />
                        Excluir os lançamentos junto
                    </label>
                </div>
                <InputError :message="errors.carteira" />

                <DialogFooter>
                    <Button variant="destructive" :disabled="processing">
                        Excluir carteira
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
