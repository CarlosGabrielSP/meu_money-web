<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, ref } from 'vue';
import { update } from '@/routes/appearance';
import type { OpcaoPaleta, Paleta } from '@/types';

const props = defineProps<{
    paletas: OpcaoPaleta[];
}>();

const page = usePage();

const atual = computed(() => page.props.auth.user.paleta);
const enviando = ref(false);

function escolher(paleta: Paleta): void {
    if (paleta === atual.value || enviando.value) {
        return;
    }

    router.patch(
        update().url,
        { paleta },
        {
            preserveScroll: true,
            onStart: () => (enviando.value = true),
            // Prefetched pages (Configurações in the user menu) keep the old props, which would
            // switch the palette back on the next visit; drop them.
            onSuccess: () => router.flushAll(),
            onFinish: () => (enviando.value = false),
        },
    );
}
</script>

<template>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <button
            v-for="{ valor: paleta, nome } in props.paletas"
            :key="paleta"
            type="button"
            :aria-pressed="atual === paleta"
            :disabled="enviando"
            :data-test="`paleta-${paleta}`"
            :class="[
                'flex flex-col gap-2 rounded-lg border p-2 text-left transition-colors hover:bg-accent disabled:opacity-60',
                atual === paleta ? 'border-primary ring-2 ring-ring/50' : '',
            ]"
            @click="escolher(paleta)"
        >
            <span class="grid grid-cols-2 overflow-hidden rounded-md border">
                <span
                    v-for="modo in ['', 'dark']"
                    :key="modo"
                    :data-paleta="paleta"
                    :class="[modo, 'flex h-12 bg-background']"
                    aria-hidden="true"
                >
                    <span class="w-2 bg-sidebar" />
                    <span class="flex flex-1 flex-col gap-1 p-1.5">
                        <span class="h-1.5 w-3/4 rounded-full bg-foreground" />
                        <span
                            class="h-1.5 w-1/2 rounded-full bg-muted-foreground"
                        />
                        <span class="mt-auto flex gap-1">
                            <span class="h-2 w-4 rounded-sm bg-primary" />
                            <span class="h-2 w-2 rounded-full bg-destaque" />
                        </span>
                    </span>
                </span>
            </span>
            <span class="flex items-center gap-1 text-sm">
                <Check v-if="atual === paleta" class="size-4 shrink-0" />
                {{ nome }}
            </span>
        </button>
    </div>
</template>
