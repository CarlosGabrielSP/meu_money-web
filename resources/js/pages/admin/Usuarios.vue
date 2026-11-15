<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import UsuarioController from '@/actions/App/Http/Controllers/Admin/UsuarioController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ref } from 'vue';
import {
    administrador,
    desativar,
    index,
    reativar,
    reenviarLink,
} from '@/routes/admin/usuarios';

type Usuario = {
    id: number;
    nome: string;
    email: string;
    isAdmin: boolean;
    ativo: boolean;
    temSenha: boolean;
};

defineProps<{
    usuarios: Usuario[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Administração', href: index() }],
    },
});

function alternarAtivo(usuario: Usuario): void {
    router.post(
        usuario.ativo ? desativar(usuario.id).url : reativar(usuario.id).url,
        {},
        { preserveScroll: true },
    );
}

const editandoEmailDe = ref<number | null>(null);

function reenviarLinkDefinirSenha(usuario: Usuario): void {
    router.post(reenviarLink(usuario.id).url, {}, { preserveScroll: true });
}

function alternarAdministrador(usuario: Usuario): void {
    router.patch(
        administrador(usuario.id).url,
        { is_admin: !usuario.isAdmin },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Administração de usuários" />

    <div class="flex flex-col gap-8 p-4">
        <section class="space-y-4">
            <Heading
                title="Novo usuário"
                description="O usuário recebe um e-mail para definir a senha"
            />

            <Form
                v-bind="UsuarioController.store.form()"
                reset-on-success
                class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="nome">Nome</Label>
                    <Input id="nome" name="nome" required />
                    <InputError :message="errors.nome" />
                </div>
                <div class="grid gap-2">
                    <Label for="email">E-mail</Label>
                    <Input id="email" name="email" type="email" required />
                    <InputError :message="errors.email" />
                </div>
                <Button :disabled="processing" data-test="criar-usuario-button">
                    Criar usuário
                </Button>
            </Form>
        </section>

        <section class="space-y-4">
            <Heading title="Usuários" />
            <InputError :message="$page.props.errors.usuario" />

            <ul class="divide-y rounded-lg border">
                <li
                    v-for="usuario in usuarios"
                    :key="usuario.id"
                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ usuario.nome }}</p>
                        <p
                            v-if="editandoEmailDe !== usuario.id"
                            class="truncate text-sm text-muted-foreground"
                        >
                            {{ usuario.email }}
                        </p>
                        <Form
                            v-else
                            v-bind="
                                UsuarioController.alterarEmail.form(usuario.id)
                            "
                            :options="{ preserveScroll: true }"
                            class="mt-1 flex flex-wrap items-start gap-2"
                            @success="editandoEmailDe = null"
                            v-slot="{ errors, processing }"
                        >
                            <div class="grid gap-1">
                                <Input
                                    name="email"
                                    type="email"
                                    :default-value="usuario.email"
                                    required
                                    aria-label="Novo e-mail"
                                />
                                <InputError :message="errors.email" />
                            </div>
                            <Button size="sm" :disabled="processing">
                                Salvar
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                @click="editandoEmailDe = null"
                            >
                                Cancelar
                            </Button>
                        </Form>
                        <div class="mt-1 flex gap-2">
                            <Badge
                                :variant="
                                    usuario.ativo ? 'secondary' : 'destructive'
                                "
                            >
                                {{ usuario.ativo ? 'Ativo' : 'Desativado' }}
                            </Badge>
                            <Badge v-if="usuario.isAdmin">Administrador</Badge>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="!usuario.temSenha"
                            variant="outline"
                            size="sm"
                            @click="reenviarLinkDefinirSenha(usuario)"
                        >
                            Reenviar link
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="editandoEmailDe = usuario.id"
                        >
                            Alterar e-mail
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="alternarAdministrador(usuario)"
                        >
                            {{
                                usuario.isAdmin
                                    ? 'Remover administrador'
                                    : 'Tornar administrador'
                            }}
                        </Button>
                        <Button
                            :variant="usuario.ativo ? 'destructive' : 'outline'"
                            size="sm"
                            @click="alternarAtivo(usuario)"
                        >
                            {{ usuario.ativo ? 'Desativar' : 'Reativar' }}
                        </Button>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
