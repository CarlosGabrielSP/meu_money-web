<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { coresCarteira, iconesCarteira } from './aparencia';

withDefaults(
    defineProps<{
        cores: string[];
        icones: string[];
        errors: Record<string, string | undefined>;
        nome?: string;
        cor?: string;
        icone?: string;
    }>(),
    { nome: '', cor: 'blue', icone: 'wallet' },
);
</script>

<template>
    <div class="grid gap-4">
        <div class="grid gap-2">
            <Label for="nome">Nome</Label>
            <Input
                id="nome"
                name="nome"
                :default-value="nome"
                maxlength="100"
                required
            />
            <InputError :message="errors.nome" />
        </div>

        <fieldset class="grid gap-2">
            <legend class="mb-2 text-sm font-medium">Cor</legend>
            <div class="flex flex-wrap gap-2">
                <label
                    v-for="opcao in cores"
                    :key="opcao"
                    class="cursor-pointer"
                >
                    <input
                        type="radio"
                        name="cor"
                        :value="opcao"
                        :checked="opcao === cor"
                        class="peer sr-only"
                    />
                    <span
                        :class="[
                            'block size-8 rounded-full ring-offset-2 ring-offset-background peer-checked:ring-2 peer-checked:ring-foreground peer-focus-visible:ring-2',
                            coresCarteira[opcao],
                        ]"
                        :aria-label="opcao"
                    />
                </label>
            </div>
            <InputError :message="errors.cor" />
        </fieldset>

        <fieldset class="grid gap-2">
            <legend class="mb-2 text-sm font-medium">Ícone</legend>
            <div class="grid grid-cols-5 gap-2 sm:grid-cols-10">
                <label
                    v-for="opcao in icones"
                    :key="opcao"
                    class="cursor-pointer"
                >
                    <input
                        type="radio"
                        name="icone"
                        :value="opcao"
                        :checked="opcao === icone"
                        class="peer sr-only"
                    />
                    <span
                        class="flex size-9 items-center justify-center rounded-md border peer-checked:border-foreground peer-checked:bg-accent peer-focus-visible:ring-2"
                        :aria-label="opcao"
                    >
                        <component :is="iconesCarteira[opcao]" class="size-5" />
                    </span>
                </label>
            </div>
            <InputError :message="errors.icone" />
        </fieldset>
    </div>
</template>
