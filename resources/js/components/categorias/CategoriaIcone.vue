<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import { iconesCategoria } from './icones';

const props = defineProps<{
    icone: string | null;
    class?: string;
}>();

const icone = computed(
    () => iconesCategoria[props.icone ?? 'tag'] ?? iconesCategoria.tag,
);
</script>

<template>
    <!-- Neutral circle: categories have no color, only the icon (RN-176). -->
    <span
        aria-hidden="true"
        :class="
            cn(
                'inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground',
                props.class,
            )
        "
    >
        <component :is="icone" class="size-[1.1rem]" />
    </span>
</template>
