<script setup lang="ts">
import { useId } from 'vue';

defineProps<{
    titulo: string;
    /** Short line under the title, such as the scope of a block that ignores the period. */
    descricao?: string;
}>();

const id = useId();
</script>

<template>
    <section
        class="grid content-start gap-4 rounded-2xl border bg-card p-4 md:p-5"
        :aria-labelledby="id"
    >
        <header class="grid gap-0.5">
            <h2 :id="id" class="font-display text-lg font-semibold">
                {{ titulo }}
            </h2>
            <p v-if="descricao" class="text-sm text-muted-foreground">
                {{ descricao }}
            </p>
        </header>
        <slot />
    </section>
</template>
