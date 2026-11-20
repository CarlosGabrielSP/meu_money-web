<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- PGMS logo, transparent variant for light and dark themes (DEC-095). -->
    <img src="/logo.png" alt="" :class="className" v-bind="$attrs" />
</template>
