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
        <!-- "PGMS" in geometric strokes (DEC-030, DEC-075). -->
        <g
            fill="none"
            stroke="#FFFFFF"
            stroke-width="30"
            stroke-linejoin="round"
        >
            <path
                d="M54 326V186H108Q122 186 122 200V244.8Q122 258.8 108 258.8H54"
            />
            <path
                d="M234 186H180Q166 186 166 200V297Q166 311 180 311H220Q234 311 234 297V263H200"
            />
            <path d="M278 326V186L312 251.8L346 186V326" />
            <path
                d="M458 186H404Q390 186 390 200V234.5Q390 248.5 404 248.5H444Q458 248.5 458 262.5V297Q458 311 444 311H390"
            />
        </g>
    </svg>
</template>
