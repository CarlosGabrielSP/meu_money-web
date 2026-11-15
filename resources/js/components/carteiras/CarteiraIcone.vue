<script setup lang="ts">
import { computed } from 'vue';
import { coresCarteira, iconesCarteira } from './aparencia';

const props = defineProps<{
    cor: string;
    icone: string;
}>();

const icone = computed(
    () => iconesCarteira[props.icone] ?? iconesCarteira.wallet,
);
</script>

<template>
    <span
        :class="[
            'inline-flex size-9 shrink-0 items-center justify-center rounded-full text-white',
            coresCarteira[cor] ?? coresCarteira.blue,
        ]"
    >
        <component :is="icone" class="size-5" />
    </span>
</template>
