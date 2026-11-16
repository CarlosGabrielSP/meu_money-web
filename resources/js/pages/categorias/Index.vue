<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CategoriaController from '@/actions/App/Http/Controllers/CategoriaController';
import CategoriaIcone from '@/components/categorias/CategoriaIcone.vue';
import { iconesCategoria, rotulosIcones } from '@/components/categorias/icones';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, index } from '@/routes/categorias';

type Tipo = 'receita' | 'despesa' | 'ambos';

type Categoria = {
    id: number;
    nome: string;
    tipo: Tipo;
    icone: string;
    doSistema: boolean;
};

const props = defineProps<{
    categorias: Categoria[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Categorias', href: index() }],
    },
});

const tipos: { valor: Tipo; titulo: string }[] = [
    { valor: 'receita', titulo: 'Receitas' },
    { valor: 'despesa', titulo: 'Despesas' },
    { valor: 'ambos', titulo: 'Receitas e despesas' },
];

const porTipo = computed(() =>
    tipos.map((tipo) => ({
        ...tipo,
        categorias: props.categorias.filter(
            (categoria) => categoria.tipo === tipo.valor,
        ),
    })),
);

const icones = Object.keys(iconesCategoria);

const dialogAberto = ref(false);
const editando = ref<Categoria | null>(null);

function abrirDialog(categoria: Categoria | null): void {
    editando.value = categoria;
    dialogAberto.value = true;
}
const excluindo = ref<Categoria | null>(null);

function excluir(): void {
    if (excluindo.value === null) {
        return;
    }

    router.delete(destroy(excluindo.value.id).url, {
        preserveScroll: true,
        onSuccess: () => (excluindo.value = null),
    });
}
</script>

<template>
    <Head title="Categorias" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Categorias"
                description="As categorias do sistema estão disponíveis para todos"
            />
            <Button
                data-test="nova-categoria-button"
                @click="abrirDialog(null)"
            >
                Nova categoria
            </Button>
        </div>

        <section v-for="grupo in porTipo" :key="grupo.valor" class="space-y-2">
            <h2 class="text-sm font-semibold text-muted-foreground">
                {{ grupo.titulo }}
            </h2>
            <ul class="divide-y overflow-hidden rounded-2xl border bg-card">
                <li
                    v-for="categoria in grupo.categorias"
                    :key="categoria.id"
                    class="flex items-center justify-between gap-3 px-4 py-3"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <CategoriaIcone :icone="categoria.icone" />
                        <span class="truncate">{{ categoria.nome }}</span>
                        <Badge v-if="categoria.doSistema" variant="secondary">
                            do sistema
                        </Badge>
                    </div>
                    <div v-if="!categoria.doSistema" class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="abrirDialog(categoria)"
                        >
                            Editar
                        </Button>
                        <Button
                            variant="destructive"
                            size="sm"
                            @click="excluindo = categoria"
                        >
                            Excluir
                        </Button>
                    </div>
                </li>
            </ul>
        </section>
    </div>

    <Dialog v-model:open="dialogAberto">
        <DialogContent>
            <Form
                v-bind="
                    editando
                        ? CategoriaController.update.form(editando.id)
                        : CategoriaController.store.form()
                "
                class="space-y-6"
                @success="dialogAberto = false"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        editando ? 'Editar categoria' : 'Nova categoria'
                    }}</DialogTitle>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="nome">Nome</Label>
                    <Input
                        id="nome"
                        name="nome"
                        maxlength="100"
                        :default-value="editando?.nome ?? ''"
                        required
                    />
                    <InputError :message="errors.nome" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Tipo</legend>
                    <label
                        v-for="tipo in tipos"
                        :key="tipo.valor"
                        class="flex items-center gap-2"
                    >
                        <input
                            type="radio"
                            name="tipo"
                            :value="tipo.valor"
                            :checked="
                                (editando?.tipo ?? 'despesa') === tipo.valor
                            "
                        />
                        {{ tipo.titulo }}
                    </label>
                    <InputError :message="errors.tipo" />
                </fieldset>

                <!-- Icon from the fixed list; a new category starts with "tag" (RN-176). -->
                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Ícone</legend>
                    <div
                        class="grid grid-cols-[repeat(auto-fill,minmax(2.25rem,1fr))] gap-2 simples:grid-cols-[repeat(auto-fill,minmax(3rem,1fr))]"
                    >
                        <label
                            v-for="opcao in icones"
                            :key="opcao"
                            class="cursor-pointer"
                        >
                            <input
                                type="radio"
                                name="icone"
                                :value="opcao"
                                :checked="(editando?.icone ?? 'tag') === opcao"
                                class="peer sr-only"
                            />
                            <span
                                class="flex size-9 items-center justify-center rounded-md border peer-checked:border-foreground peer-checked:bg-accent peer-focus-visible:ring-2 simples:size-12"
                                :aria-label="rotulosIcones[opcao]"
                            >
                                <component
                                    :is="iconesCategoria[opcao]"
                                    class="size-5"
                                />
                            </span>
                        </label>
                    </div>
                    <InputError :message="errors.icone" />
                </fieldset>

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
            <DialogHeader>
                <DialogTitle>Excluir {{ excluindo.nome }}?</DialogTitle>
                <DialogDescription>
                    Lançamentos e séries com esta categoria passam para
                    "Outros".
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="destructive" @click="excluir"
                    >Excluir categoria</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
