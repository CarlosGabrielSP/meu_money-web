<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { request, update } from '@/routes/password';

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
    tokenValido: boolean;
    temSenha: boolean;
}>();

const titulo = computed(() =>
    props.temSenha ? 'Redefinir senha' : 'Definir senha',
);

setLayoutProps({
    title: titulo.value,
    description: props.tokenValido
        ? 'Informe sua nova senha'
        : 'Este link é inválido ou expirou',
});

const inputEmail = ref(props.email);
</script>

<template>
    <Head :title="titulo" />

    <div v-if="!tokenValido" class="space-y-6 text-center">
        <p class="text-sm text-red-600" data-test="link-invalido">
            Este link para definir a senha é inválido ou expirou.
        </p>
        <TextLink :href="request()" data-test="pedir-novo-link">
            Pedir um novo link
        </TextLink>
    </div>

    <Form
        v-else
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">E-mail</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    v-model="inputEmail"
                    class="mt-1 block w-full"
                    readonly
                />
                <InputError :message="errors.email" class="mt-2" />
                <TextLink v-if="errors.email" :href="request()" class="text-sm">
                    Pedir um novo link
                </TextLink>
            </div>

            <div class="grid gap-2">
                <Label for="password">Senha</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    autofocus
                    placeholder="Senha"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirmar senha</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    placeholder="Confirmar senha"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                {{ titulo }}
            </Button>
        </div>
    </Form>
</template>
