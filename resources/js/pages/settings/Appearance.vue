<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import InterruptorModoSimples from '@/components/InterruptorModoSimples.vue';
import SeletorPaleta from '@/components/SeletorPaleta.vue';
import { edit } from '@/routes/appearance';
import type { OpcaoPaleta } from '@/types';

defineProps<{
    paletas: OpcaoPaleta[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Aparência',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Aparência" />

    <h1 class="sr-only">Aparência</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Tema"
            description="Claro, escuro ou do sistema. Vale só neste aparelho."
        />
        <AppearanceTabs />

        <Heading
            variant="small"
            title="Paleta"
            description="Cores do aplicativo. Vale em todos os seus aparelhos."
        />
        <SeletorPaleta :paletas="paletas" />

        <Heading
            variant="small"
            title="Modo simples"
            description="Vale em todos os seus aparelhos."
        />
        <InterruptorModoSimples />
    </div>
</template>
