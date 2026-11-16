<script setup lang="ts">
import { useEventListener } from '@vueuse/core';
import {
    PopoverAnchor,
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
} from 'reka-ui';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useSidebar } from '@/components/ui/sidebar';
import { elementoVisivel, rolarAte } from '@/lib/tutorial';
import type { PassoTutorial } from '@/lib/tutorial';

const props = defineProps<{
    passos: PassoTutorial[];
    /** The save is running: the buttons wait for it (DEC-069). */
    terminando: boolean;
}>();

const emit = defineEmits<{
    terminar: [];
}>();

type PassoNaTela = { texto: string; elemento: HTMLElement };

const passosNaTela = ref<PassoNaTela[]>([]);
const indice = ref(0);
const retangulo = ref<DOMRect | null>(null);
const botaoProximo = ref<InstanceType<typeof Button> | null>(null);
const { setOpenMobile } = useSidebar();

const passo = computed<PassoNaTela | undefined>(
    () => passosNaTela.value[indice.value],
);
const ultimo = computed(() => indice.value === passosNaTela.value.length - 1);

const destaque = computed(() => {
    if (retangulo.value === null) {
        return {};
    }

    const margem = 4;

    return {
        top: `${retangulo.value.top - margem}px`,
        left: `${retangulo.value.left - margem}px`,
        width: `${retangulo.value.width + margem * 2}px`,
        height: `${retangulo.value.height + margem * 2}px`,
    };
});

function medir(): void {
    retangulo.value = passo.value?.elemento.getBoundingClientRect() ?? null;
}

let quadroPendente = false;

/**
 * Scroll and resize fire many times per frame; the highlight is measured once per frame.
 */
function medirNoProximoQuadro(): void {
    if (quadroPendente) {
        return;
    }

    quadroPendente = true;
    requestAnimationFrame(() => {
        quadroPendente = false;
        medir();
    });
}

function proximo(): void {
    if (ultimo.value) {
        emit('terminar');

        return;
    }

    indice.value++;
}

function voltar(): void {
    if (indice.value > 0) {
        indice.value--;
    }
}

watch(passo, async (atual) => {
    if (atual === undefined) {
        return;
    }

    rolarAte(atual.elemento);
    medir();
    await nextTick();
    (botaoProximo.value?.$el as HTMLElement | undefined)?.focus();
});

useEventListener(window, 'resize', medirNoProximoQuadro);
useEventListener(window, 'scroll', medirNoProximoQuadro, {
    capture: true,
    passive: true,
});

/**
 * Steps whose element is not on the screen are skipped (RN-164); the list steps point to the
 * empty list area when there are no entries (DEC-069).
 */
onMounted(() => {
    // The mobile menu ("Mais") may still be open from the last visit; the tour closes it.
    setOpenMobile(false);

    requestAnimationFrame(() => {
        passosNaTela.value = props.passos.flatMap((item) => {
            const elemento = item.alvos
                .map(elementoVisivel)
                .find((encontrado) => encontrado !== null);

            return elemento ? [{ texto: item.texto, elemento }] : [];
        });

        if (passosNaTela.value.length === 0) {
            emit('terminar');
        }
    });
});
</script>

<template>
    <div v-if="passo">
        <!-- Blocks every click on the page while the tour is open; only the balloon gets clicks (DEC-069). -->
        <div class="fixed inset-0 z-50" aria-hidden="true" />
        <div
            class="pointer-events-none fixed z-50 rounded-md ring-2 ring-destaque transition-all duration-200"
            :style="{ ...destaque, boxShadow: '0 0 0 9999px rgb(0 0 0 / 0.6)' }"
            aria-hidden="true"
        />

        <!-- Modal: focus stays in the balloon and the rest of the page is hidden from assistive
             technology, so the keyboard cannot reach the page either (DEC-069). -->
        <PopoverRoot :open="true" :modal="true">
            <PopoverAnchor :reference="passo.elemento" />
            <PopoverPortal>
                <PopoverContent
                    side="bottom"
                    :side-offset="12"
                    :collision-padding="16"
                    aria-label="Tutorial"
                    data-test="tutorial"
                    class="z-[60] grid w-80 max-w-[calc(100vw-2rem)] gap-4 rounded-lg border bg-popover p-4 text-popover-foreground shadow-lg outline-hidden"
                    @escape-key-down.prevent="emit('terminar')"
                    @pointer-down-outside.prevent
                    @focus-outside.prevent
                    @interact-outside.prevent
                >
                    <p aria-live="polite">{{ passo.texto }}</p>

                    <div class="flex items-center gap-2">
                        <div class="flex gap-1" aria-hidden="true">
                            <span
                                v-for="(_, posicao) in passosNaTela"
                                :key="posicao"
                                class="size-2 rounded-full"
                                :class="
                                    posicao === indice
                                        ? 'bg-destaque'
                                        : 'bg-muted-foreground/30'
                                "
                            />
                        </div>
                        <span class="text-xs text-muted-foreground">
                            {{ indice + 1 }} de {{ passosNaTela.length }}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            data-test="tutorial-pular"
                            :disabled="terminando"
                            @click="emit('terminar')"
                        >
                            Pular
                        </Button>
                        <div class="ml-auto flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="indice === 0 || terminando"
                                @click="voltar"
                            >
                                Voltar
                            </Button>
                            <Button
                                ref="botaoProximo"
                                size="sm"
                                data-test="tutorial-proximo"
                                :disabled="terminando"
                                @click="proximo"
                            >
                                {{ ultimo ? 'Concluir' : 'Próximo' }}
                            </Button>
                        </div>
                    </div>
                </PopoverContent>
            </PopoverPortal>
        </PopoverRoot>
    </div>
</template>
