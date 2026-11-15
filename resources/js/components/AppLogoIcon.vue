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
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 512 512"
        :class="className"
        v-bind="$attrs"
    >
        <rect width="512" height="512" rx="112" fill="#FF2D20" />
        <g
            fill="none"
            stroke="#FFFFFF"
            stroke-width="34"
            stroke-linejoin="round"
        >
            <path d="M73 331V198H142Q159 198 159 215V250Q159 267 142 267H73" />
            <path
                d="M299 198H230Q213 198 213 215V297Q213 314 230 314H282Q299 314 299 297V262H258"
            />
            <path d="M353 331V198L396 260L439 198V331" />
        </g>
    </svg>
</template>
