<script setup lang="ts">
import { Eye, EyeOff } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { usePrivacidadeDosValores } from '@/lib/dinheiro';

const valoresOcultos = usePrivacidadeDosValores();
const acao = computed(() =>
    valoresOcultos.value ? 'Mostrar valores' : 'Ocultar valores',
);
</script>

<template>
    <Button
        type="button"
        variant="outline"
        size="icon"
        class="rounded-full text-foreground"
        :aria-label="acao"
        :title="acao"
        @click="valoresOcultos = !valoresOcultos"
    >
        <EyeOff v-if="valoresOcultos" aria-hidden="true" />
        <Eye v-else aria-hidden="true" />
    </Button>
</template>
