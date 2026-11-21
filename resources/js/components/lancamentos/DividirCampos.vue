<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { centavosDe, formatarMoeda, mascararValor } from '@/lib/dinheiro';
import { calcularCotas } from '@/lib/rateio';
import type { ContatoOpcao, ModoRateio, TipoLancamento } from '@/types';

export type ParticipanteDivisao = {
    userId: number;
    nome: string;
    percentual: string;
    valor: string;
};

export type Divisao = {
    ativa: boolean;
    modo: ModoRateio;
    autorParticipa: boolean;
    participantes: ParticipanteDivisao[];
};

const divisao = defineModel<Divisao>({ required: true });

const props = defineProps<{
    contatos: ContatoOpcao[];
    autorId: number;
    /** A member sees the owner by name instead of "Eu" (nota 07, Fase 16). */
    nomeDoAutor?: string;
    total: number;
    bloqueado: boolean;
    /** Lock reason when it is not an executed share, e.g. a settled part (RN-258). */
    aviso?: string;
    /** Names the executed share "paga" or "recebida" (DEC-093). */
    tipo: TipoLancamento;
    erro?: string;
}>();

const modos: { valor: ModoRateio; titulo: string }[] = [
    { valor: 'iguais', titulo: 'Partes iguais' },
    { valor: 'percentual', titulo: 'Percentual' },
    { valor: 'fixo', titulo: 'Valor fixo' },
];

/**
 * Contacts plus current participants who may no longer be contacts (RN-064).
 */
const opcoes = computed<ContatoOpcao[]>(() => {
    const extras = divisao.value.participantes
        .filter(
            (participante) =>
                participante.userId !== props.autorId &&
                !props.contatos.some(
                    (contato) => contato.id === participante.userId,
                ),
        )
        .map((participante) => ({
            id: participante.userId,
            nome: participante.nome,
        }));

    return [...props.contatos, ...extras];
});

const incluidos = computed(() => {
    const autor = divisao.value.participantes.find(
        (participante) => participante.userId === props.autorId,
    );
    const outros = divisao.value.participantes.filter(
        (participante) => participante.userId !== props.autorId,
    );

    return divisao.value.autorParticipa && autor ? [autor, ...outros] : outros;
});

const previa = computed(() =>
    calcularCotas(
        props.total,
        divisao.value.modo,
        incluidos.value.map((participante) => ({
            userId: participante.userId,
            percentual: participante.percentual,
            valor: centavosDe(participante.valor),
        })),
        props.autorId,
    ),
);

function participa(userId: number): boolean {
    return divisao.value.participantes.some(
        (participante) => participante.userId === userId,
    );
}

function alternar(contato: ContatoOpcao, incluir: boolean): void {
    divisao.value.participantes = incluir
        ? [
              ...divisao.value.participantes,
              {
                  userId: contato.id,
                  nome: contato.nome,
                  percentual: '',
                  valor: '',
              },
          ]
        : divisao.value.participantes.filter(
              (participante) => participante.userId !== contato.id,
          );
}

function alternarAutor(incluir: boolean): void {
    divisao.value.autorParticipa = incluir;

    if (incluir && !participa(props.autorId)) {
        divisao.value.participantes = [
            {
                userId: props.autorId,
                nome: props.nomeDoAutor ?? 'Eu',
                percentual: '',
                valor: '',
            },
            ...divisao.value.participantes,
        ];
    }
}
</script>

<template>
    <div class="grid gap-3 rounded-md border p-3">
        <label class="flex items-center gap-2 font-medium">
            <Checkbox
                :model-value="divisao.ativa"
                :disabled="bloqueado"
                @update:model-value="divisao.ativa = $event === true"
            />
            Dividir
        </label>

        <template v-if="divisao.ativa">
            <p v-if="bloqueado" class="text-sm text-muted-foreground">
                {{
                    aviso ??
                    `Um participante já marcou a cota como ${tipo === 'receita' ? 'recebida' : 'paga'}. Valor e divisão não podem mudar.`
                }}
            </p>

            <fieldset class="flex flex-wrap gap-4" :disabled="bloqueado">
                <legend class="sr-only">Modo de divisão</legend>
                <label
                    v-for="modo in modos"
                    :key="modo.valor"
                    class="flex items-center gap-2"
                >
                    <input
                        v-model="divisao.modo"
                        type="radio"
                        :value="modo.valor"
                    />
                    {{ modo.titulo }}
                </label>
            </fieldset>

            <fieldset class="grid gap-2" :disabled="bloqueado">
                <legend class="mb-1 text-sm font-medium">Participantes</legend>
                <label class="flex items-center gap-2">
                    <Checkbox
                        :model-value="divisao.autorParticipa"
                        :disabled="bloqueado"
                        @update:model-value="alternarAutor($event === true)"
                    />
                    {{
                        nomeDoAutor
                            ? `${nomeDoAutor} participa`
                            : 'Eu participo'
                    }}
                </label>
                <label
                    v-for="contato in opcoes"
                    :key="contato.id"
                    class="flex items-center gap-2"
                >
                    <Checkbox
                        :model-value="participa(contato.id)"
                        :disabled="bloqueado"
                        @update:model-value="alternar(contato, $event === true)"
                    />
                    {{ contato.nome }}
                </label>
                <p
                    v-if="opcoes.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Convide contatos na tela Contatos para dividir lançamentos.
                </p>
            </fieldset>

            <ul v-if="incluidos.length > 0" class="grid gap-2 text-sm">
                <li
                    v-for="participante in incluidos"
                    :key="participante.userId"
                    class="flex items-center justify-between gap-2"
                >
                    <span class="truncate">{{
                        participante.userId === autorId
                            ? (nomeDoAutor ?? 'Eu')
                            : participante.nome
                    }}</span>
                    <span class="flex items-center gap-2">
                        <Input
                            v-if="divisao.modo === 'percentual'"
                            v-model="participante.percentual"
                            class="h-8 w-20"
                            inputmode="decimal"
                            placeholder="%"
                            :disabled="bloqueado"
                            :aria-label="`Percentual de ${participante.nome}`"
                        />
                        <Input
                            v-if="divisao.modo === 'fixo'"
                            :model-value="participante.valor"
                            class="h-8 w-28"
                            inputmode="numeric"
                            placeholder="0,00"
                            :disabled="bloqueado"
                            :aria-label="`Valor de ${participante.nome}`"
                            @update:model-value="
                                participante.valor = mascararValor(
                                    String($event),
                                )
                            "
                        />
                        <span class="w-24 text-right tabular-nums">{{
                            formatarMoeda(previa.get(participante.userId) ?? 0)
                        }}</span>
                    </span>
                </li>
            </ul>
            <InputError :message="erro" />
        </template>
    </div>
</template>
