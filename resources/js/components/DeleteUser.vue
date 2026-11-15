<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed, useTemplateRef } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const page = usePage();
const temSenha = computed(() => page.props.auth.temSenha);
const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Excluir conta"
            description="Exclua sua conta e todos os seus dados"
        />
        <div
            class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
        >
            <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">Atenção</p>
                <p class="text-sm">Esta ação não pode ser desfeita.</p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive" data-test="delete-user-button"
                        >Excluir conta</Button
                    >
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        @error="() => passwordInput?.focus()"
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle
                                >Tem certeza que deseja excluir sua
                                conta?</DialogTitle
                            >
                            <DialogDescription>
                                Todos os seus dados serão excluídos
                                definitivamente.
                                {{
                                    temSenha
                                        ? 'Digite sua senha para confirmar.'
                                        : 'Digite seu e-mail para confirmar.'
                                }}
                            </DialogDescription>
                        </DialogHeader>

                        <div v-if="temSenha" class="grid gap-2">
                            <Label for="password" class="sr-only">Senha</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                ref="passwordInput"
                                placeholder="Senha"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <div v-else class="grid gap-2">
                            <Label for="confirmacao_email" class="sr-only"
                                >Seu e-mail</Label
                            >
                            <Input
                                id="confirmacao_email"
                                name="confirmacao_email"
                                type="email"
                                autocomplete="off"
                                placeholder="Seu e-mail"
                            />
                            <InputError :message="errors.confirmacao_email" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    variant="secondary"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    Cancelar
                                </Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                Excluir conta
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
