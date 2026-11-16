<script setup lang="ts">
import { Form, Head, router, useForm } from '@inertiajs/vue3';
import UsuarioController from '@/actions/App/Http/Controllers/Admin/UsuarioController';
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
import { hojeEmSaoPaulo } from '@/lib/datas';
import { computed, ref } from 'vue';
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

/**
 * The list never carries the birth date (CA-110.5), so the dialog opens empty. "Salvar" needs a
 * date; removing is its own button, so an empty "Salvar" never erases a date by mistake (DEC-068).
 */
const dataNascimentoDe = ref<Usuario | null>(null);
const formularioData = useForm<{ data_nascimento: string }>({
    data_nascimento: '',
});
const dialogoDataAberto = computed({
    get: () => dataNascimentoDe.value !== null,
    set: (aberto: boolean) => {
        if (!aberto) {
            dataNascimentoDe.value = null;
        }
    },
});

function abrirDataNascimento(usuario: Usuario): void {
    formularioData.reset();
    formularioData.clearErrors();
    dataNascimentoDe.value = usuario;
}

function enviarDataNascimento(remover: boolean): void {
    if (dataNascimentoDe.value === null) {
        return;
    }

    formularioData
        .transform((campos) => (remover ? { data_nascimento: '' } : campos))
        .patch(
            UsuarioController.alterarDataNascimento.url(
                dataNascimentoDe.value.id,
            ),
            {
                preserveScroll: true,
                onSuccess: () => (dataNascimentoDe.value = null),
            },
        );
}

const hoje = hojeEmSaoPaulo();

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
                class="grid gap-4 sm:grid-cols-[1fr_1fr_auto_auto] sm:items-end"
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
                <div class="grid gap-2">
                    <Label for="data_nascimento">
                        Data de nascimento (opcional)
                    </Label>
                    <Input
                        id="data_nascimento"
                        name="data_nascimento"
                        type="date"
                        min="1900-01-01"
                        :max="hoje"
                    />
                    <InputError :message="errors.data_nascimento" />
                </div>
                <Button :disabled="processing" data-test="criar-usuario-button">
                    Criar usuário
                </Button>
            </Form>
        </section>

        <section class="space-y-4">
            <Heading title="Usuários" />
            <InputError :message="$page.props.errors.usuario" />

            <ul class="divide-y overflow-hidden rounded-2xl border bg-card">
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
                            @click="abrirDataNascimento(usuario)"
                        >
                            Data de nascimento
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

        <Dialog v-model:open="dialogoDataAberto">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Data de nascimento</DialogTitle>
                    <DialogDescription>
                        {{ dataNascimentoDe?.nome }}. A data atual não é
                        mostrada. Informe a nova data ou use "Remover data".
                    </DialogDescription>
                </DialogHeader>
                <form
                    class="grid gap-4"
                    @submit.prevent="enviarDataNascimento(false)"
                >
                    <div class="grid gap-2">
                        <Label for="nova_data_nascimento">
                            Nova data de nascimento
                        </Label>
                        <Input
                            id="nova_data_nascimento"
                            v-model="formularioData.data_nascimento"
                            type="date"
                            min="1900-01-01"
                            :max="hoje"
                        />
                        <InputError
                            :message="formularioData.errors.data_nascimento"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="destructive"
                            class="sm:mr-auto"
                            :disabled="formularioData.processing"
                            data-test="remover-data-nascimento"
                            @click="enviarDataNascimento(true)"
                        >
                            Remover data
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="dataNascimentoDe = null"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            :disabled="
                                formularioData.processing ||
                                !formularioData.data_nascimento
                            "
                        >
                            Salvar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
