<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import CampoSelecao from '@/components/CampoSelecao.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { coresCarteira, iconesCarteira } from './aparencia';

export type CompetenciaFatura = 'fechamento' | 'vencimento';

export type DadosCartao = {
    diaVencimento: number;
    diasFechamento: number;
    competencia: CompetenciaFatura;
    carteiraVinculadaId: number | null;
};

const props = withDefaults(
    defineProps<{
        cores: string[];
        icones: string[];
        errors: Record<string, string | undefined>;
        nome?: string;
        cor?: string;
        icone?: string;
        /** Type of a wallet being edited; null when creating (RN-190). */
        tipo?: 'comum' | 'cartao' | null;
        cartao?: DadosCartao | null;
        carteirasComuns?: { id: number; nome: string }[];
        simples?: boolean;
        /** Author of the card a mirror follows; null for any other wallet (RF-182). */
        autorDoEspelho?: string | null;
    }>(),
    {
        nome: '',
        cor: 'blue',
        icone: 'wallet',
        tipo: null,
        cartao: null,
        carteirasComuns: () => [],
        simples: false,
        autorDoEspelho: null,
    },
);

const tipoEscolhido = ref<'comum' | 'cartao'>(props.tipo ?? 'comum');
const iconeEscolhido = ref(props.icone);
const iconeMexido = ref(false);
const vinculadaId = ref<number | null>(
    props.cartao?.carteiraVinculadaId ?? props.carteirasComuns[0]?.id ?? null,
);
const ehCartao = computed(() => tipoEscolhido.value === 'cartao');
const dias = Array.from({ length: 31 }, (_, indice) => indice + 1);
const diaVencimento = ref<number | null>(props.cartao?.diaVencimento ?? 10);

/**
 * Choosing "Cartão de crédito" sets the card icon while the user did not pick another (nota 07).
 */
watch(tipoEscolhido, (tipo) => {
    if (!iconeMexido.value) {
        iconeEscolhido.value = tipo === 'cartao' ? 'credit-card' : props.icone;
    }
});
</script>

<template>
    <div class="grid gap-4">
        <fieldset v-if="tipo === null" class="grid gap-2">
            <legend class="mb-2 text-sm font-medium">Tipo</legend>
            <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2">
                    <input
                        v-model="tipoEscolhido"
                        type="radio"
                        name="tipo"
                        value="comum"
                    />
                    Carteira
                </label>
                <label class="flex items-center gap-2">
                    <input
                        v-model="tipoEscolhido"
                        type="radio"
                        name="tipo"
                        value="cartao"
                    />
                    Cartão de crédito
                </label>
            </div>
            <InputError :message="errors.tipo" />
        </fieldset>
        <p v-else class="text-sm text-muted-foreground">
            Tipo: {{ tipo === 'cartao' ? 'Cartão de crédito' : 'Carteira' }}
        </p>

        <div class="grid gap-2">
            <Label for="nome">Nome</Label>
            <Input
                id="nome"
                name="nome"
                :default-value="nome"
                maxlength="20"
                required
            />
            <InputError :message="errors.nome" />
        </div>

        <fieldset v-if="!(simples && ehCartao)" class="grid gap-2">
            <legend class="mb-2 text-sm font-medium">Cor</legend>
            <div class="flex flex-wrap gap-2">
                <label
                    v-for="opcao in cores"
                    :key="opcao"
                    class="cursor-pointer"
                >
                    <input
                        type="radio"
                        name="cor"
                        :value="opcao"
                        :checked="opcao === cor"
                        class="peer sr-only"
                    />
                    <span
                        :class="[
                            'block size-8 rounded-full ring-offset-2 ring-offset-background peer-checked:ring-2 peer-checked:ring-foreground peer-focus-visible:ring-2',
                            coresCarteira[opcao],
                        ]"
                        :aria-label="opcao"
                    />
                </label>
            </div>
            <InputError :message="errors.cor" />
        </fieldset>

        <fieldset v-if="!(simples && ehCartao)" class="grid gap-2">
            <legend class="mb-2 text-sm font-medium">Ícone</legend>
            <div class="grid grid-cols-5 gap-2 sm:grid-cols-10">
                <label
                    v-for="opcao in icones"
                    :key="opcao"
                    class="cursor-pointer"
                >
                    <input
                        v-model="iconeEscolhido"
                        type="radio"
                        name="icone"
                        :value="opcao"
                        class="peer sr-only"
                        @change="iconeMexido = true"
                    />
                    <span
                        class="flex size-9 items-center justify-center rounded-md border peer-checked:border-foreground peer-checked:bg-accent peer-focus-visible:ring-2"
                        :aria-label="opcao"
                    >
                        <component :is="iconesCarteira[opcao]" class="size-5" />
                    </span>
                </label>
            </div>
            <InputError :message="errors.icone" />
        </fieldset>
        <input v-else type="hidden" name="icone" :value="iconeEscolhido" />

        <!-- A mirror shows the author's dates as text and is always paid by a common wallet (RF-182, nota 07). -->
        <div v-if="ehCartao && autorDoEspelho !== null" class="grid gap-1">
            <p class="text-sm">
                Vence dia {{ cartao?.diaVencimento }} · fecha
                {{ cartao?.diasFechamento }} dias antes
            </p>
            <p class="text-sm text-muted-foreground">
                Definidos pelo cartão de {{ autorDoEspelho }}.
            </p>
        </div>

        <template v-if="ehCartao && autorDoEspelho === null">
            <div class="grid gap-2">
                <Label for="dia_vencimento">Dia do vencimento</Label>
                <CampoSelecao
                    id="dia_vencimento"
                    v-model="diaVencimento"
                    :opcoes="
                        dias.map((dia) => ({ valor: dia, rotulo: String(dia) }))
                    "
                />
                <input
                    type="hidden"
                    name="dia_vencimento"
                    :value="diaVencimento ?? ''"
                />
                <InputError :message="errors.dia_vencimento" />
            </div>

            <div v-if="!simples" class="grid gap-2">
                <Label for="dias_fechamento">
                    Fecha quantos dias antes do vencimento
                </Label>
                <Input
                    id="dias_fechamento"
                    name="dias_fechamento"
                    type="number"
                    min="1"
                    max="25"
                    :default-value="cartao?.diasFechamento ?? 10"
                />
                <InputError :message="errors.dias_fechamento" />
            </div>

            <fieldset v-if="!simples" class="grid gap-2">
                <legend class="mb-2 text-sm font-medium">
                    Nome da fatura pelo mês
                </legend>
                <div class="flex flex-wrap gap-4">
                    <label class="flex items-center gap-2">
                        <input
                            type="radio"
                            name="competencia"
                            value="fechamento"
                            :checked="
                                (cartao?.competencia ?? 'fechamento') ===
                                'fechamento'
                            "
                        />
                        do fechamento
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            type="radio"
                            name="competencia"
                            value="vencimento"
                            :checked="cartao?.competencia === 'vencimento'"
                        />
                        do vencimento
                    </label>
                </div>
                <p class="text-sm text-muted-foreground">
                    Use o mesmo do seu banco. Fechamento 25/10 e vencimento
                    05/11: fatura de outubro ou de novembro.
                </p>
                <InputError :message="errors.competencia" />
            </fieldset>
        </template>

        <template v-if="ehCartao">
            <div class="grid gap-2">
                <Label for="carteira_vinculada"
                    >Carteira que paga a fatura</Label
                >
                <CampoSelecao
                    id="carteira_vinculada"
                    v-model="vinculadaId"
                    :opcoes="[
                        ...carteirasComuns.map((carteira) => ({
                            valor: carteira.id,
                            rotulo: carteira.nome,
                        })),
                        ...(autorDoEspelho === null
                            ? [
                                  {
                                      valor: null,
                                      rotulo: 'Nenhuma, cartão independente',
                                  },
                              ]
                            : []),
                    ]"
                />
                <input
                    type="hidden"
                    name="carteira_vinculada_id"
                    :value="vinculadaId ?? ''"
                />
                <p
                    v-if="autorDoEspelho === null"
                    class="text-sm text-muted-foreground"
                >
                    Sem carteira, o pagamento da fatura não mexe em nenhum
                    saldo.
                </p>
                <InputError :message="errors.carteira_vinculada_id" />
            </div>
        </template>
    </div>
</template>
