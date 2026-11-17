<script setup lang="ts" generic="Valor extends string | number">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

export type OpcaoSelecao<V> = {
    valor: V | null;
    rotulo: string;
};

/**
 * Select of the UI library in place of the native one (DEC-078). The library does not accept an
 * empty value, so `null` (for example "Todos" in a filter) travels as an internal key.
 */
const props = defineProps<{
    modelValue: Valor | null;
    opcoes: OpcaoSelecao<Valor>[];
    id?: string;
    name?: string;
    placeholder?: string;
    /** Accessible name when there is no visible label. */
    rotulo?: string;
    required?: boolean;
    disabled?: boolean;
    class?: HTMLAttributes['class'];
}>();

const emit = defineEmits<{
    'update:modelValue': [valor: Valor | null];
}>();

const VAZIO = '__vazio__';

function chave(valor: Valor | null): string {
    return valor === null ? VAZIO : String(valor);
}

const selecionada = computed(() =>
    props.opcoes.some((opcao) => opcao.valor === props.modelValue)
        ? chave(props.modelValue)
        : undefined,
);

function escolher(valor: unknown): void {
    const opcao = props.opcoes.find((item) => chave(item.valor) === valor);

    if (opcao !== undefined) {
        emit('update:modelValue', opcao.valor);
    }
}
</script>

<template>
    <Select
        :model-value="selecionada"
        :name="name"
        :required="required"
        :disabled="disabled"
        @update:model-value="escolher"
    >
        <SelectTrigger
            :id="id"
            :aria-label="rotulo"
            :class="cn('w-full min-w-0', props.class)"
        >
            <SelectValue :placeholder="placeholder" />
        </SelectTrigger>
        <SelectContent>
            <SelectItem
                v-for="opcao in opcoes"
                :key="chave(opcao.valor)"
                :value="chave(opcao.valor)"
            >
                {{ opcao.rotulo }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
