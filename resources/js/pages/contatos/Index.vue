<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ContatoController from '@/actions/App/Http/Controllers/ContatoController';
import Heading from '@/components/Heading.vue';
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
import { index } from '@/routes/contatos';

type Pessoa = {
    id: number;
    nome: string;
    email: string;
};

defineProps<{
    contatos: Pessoa[];
    recebidos: Pessoa[];
    enviados: Pessoa[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Contatos', href: index() }],
    },
});

const removendo = ref<Pessoa | null>(null);
const processando = ref(false);

function responder(convite: Pessoa, aceitar: boolean): void {
    const rota = aceitar
        ? ContatoController.aceitar.url(convite.id)
        : ContatoController.recusar.url(convite.id);

    router.post(
        rota,
        {},
        {
            preserveScroll: true,
            onStart: () => (processando.value = true),
            onFinish: () => (processando.value = false),
        },
    );
}

function excluir(registro: Pessoa, onSuccess?: () => void): void {
    router.delete(ContatoController.destroy.url(registro.id), {
        preserveScroll: true,
        onStart: () => (processando.value = true),
        onFinish: () => (processando.value = false),
        onSuccess,
    });
}
</script>

<template>
    <Head title="Contatos" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Contatos"
            description="Pessoas com quem você pode dividir lançamentos"
        />

        <Form
            v-bind="ContatoController.store.form()"
            reset-on-success
            class="flex flex-col gap-2 sm:flex-row sm:items-end"
            v-slot="{ errors, processing }"
        >
            <div class="grid flex-1 gap-2">
                <Label for="email">Convidar por e-mail</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    autocomplete="off"
                    placeholder="pessoa@exemplo.com"
                    required
                />
                <InputError :message="errors.email" />
            </div>
            <Button :disabled="processing" data-test="convidar-button">
                Convidar
            </Button>
        </Form>

        <section v-if="recebidos.length > 0" class="grid gap-2">
            <h2 class="text-sm font-semibold">Convites recebidos</h2>
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="convite in recebidos"
                    :key="convite.id"
                    class="flex flex-wrap items-center justify-between gap-2 p-3"
                >
                    <span class="min-w-0">
                        <span class="block truncate font-medium">{{
                            convite.nome
                        }}</span>
                        <span
                            class="block truncate text-sm text-muted-foreground"
                            >{{ convite.email }}</span
                        >
                    </span>
                    <span class="flex gap-2">
                        <Button
                            :disabled="processando"
                            size="sm"
                            @click="responder(convite, true)"
                        >
                            Aceitar
                        </Button>
                        <Button
                            :disabled="processando"
                            size="sm"
                            variant="outline"
                            @click="responder(convite, false)"
                        >
                            Recusar
                        </Button>
                    </span>
                </li>
            </ul>
        </section>

        <section class="grid gap-2">
            <h2 class="text-sm font-semibold">Meus contatos</h2>
            <p
                v-if="contatos.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Você ainda não tem contatos. Convide alguém pelo e-mail.
            </p>
            <ul v-else class="divide-y rounded-lg border">
                <li
                    v-for="contato in contatos"
                    :key="contato.id"
                    class="flex items-center justify-between gap-2 p-3"
                >
                    <span class="min-w-0">
                        <span class="block truncate font-medium">{{
                            contato.nome
                        }}</span>
                        <span
                            class="block truncate text-sm text-muted-foreground"
                            >{{ contato.email }}</span
                        >
                    </span>
                    <Button
                        :disabled="processando"
                        size="sm"
                        variant="ghost"
                        @click="removendo = contato"
                    >
                        Remover
                    </Button>
                </li>
            </ul>
        </section>

        <section v-if="enviados.length > 0" class="grid gap-2">
            <h2 class="text-sm font-semibold">Convites enviados</h2>
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="convite in enviados"
                    :key="convite.id"
                    class="flex items-center justify-between gap-2 p-3"
                >
                    <span class="min-w-0">
                        <span class="block truncate font-medium">{{
                            convite.nome
                        }}</span>
                        <span
                            class="block truncate text-sm text-muted-foreground"
                            >{{ convite.email }} · aguardando resposta</span
                        >
                    </span>
                    <Button
                        :disabled="processando"
                        size="sm"
                        variant="ghost"
                        @click="excluir(convite)"
                    >
                        Cancelar
                    </Button>
                </li>
            </ul>
        </section>

        <Dialog
            :open="removendo !== null"
            @update:open="removendo = $event ? removendo : null"
        >
            <DialogContent v-if="removendo">
                <DialogHeader>
                    <DialogTitle>Remover {{ removendo.nome }}?</DialogTitle>
                    <DialogDescription>
                        Lançamentos já divididos continuam como estão. Vocês só
                        não poderão dividir novos lançamentos.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="destructive"
                        :disabled="processando"
                        @click="excluir(removendo, () => (removendo = null))"
                    >
                        Remover
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
