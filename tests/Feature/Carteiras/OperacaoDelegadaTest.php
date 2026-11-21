<?php

use App\Enums\StatusMembro;
use App\Enums\TipoCategoria;
use App\Models\Carteira;
use App\Models\CarteiraMembro;
use App\Models\Categoria;
use App\Models\Contato;
use App\Models\Cota;
use App\Models\Grupo;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriasGlobaisSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->seed(CategoriasGlobaisSeeder::class);
    $this->dono = User::factory()->create(['name' => 'Carlos']);
    $this->membro = User::factory()->create(['name' => 'Ana']);
    $this->principal = Carteira::factory()->for($this->dono)->padrao()->create(['nome' => 'Principal']);
    $this->reserva = Carteira::factory()->for($this->dono)->create(['nome' => 'Reserva']);
    $this->daAna = Carteira::factory()->for($this->membro)->padrao()->create(['nome' => 'Da Ana']);
    $this->categoriaDoDono = Categoria::factory()->for($this->dono)->create(['nome' => 'Feira', 'tipo' => TipoCategoria::Despesa]);
    $this->categoriaDaAna = Categoria::factory()->for($this->membro)->create(['nome' => 'Pet', 'tipo' => TipoCategoria::Despesa]);
    Contato::factory()->aceito()->create(['solicitante_id' => $this->dono->id, 'convidado_id' => $this->membro->id]);
    $this->delegacao = delegarCarteira($this->principal, $this->membro);
});

function delegarCarteira(Carteira $carteira, User $user, StatusMembro $status = StatusMembro::Aceito): CarteiraMembro
{
    return CarteiraMembro::factory()->create([
        'carteira_id' => $carteira->id,
        'user_id' => $user->id,
        'status' => $status,
        'respondido_em' => $status === StatusMembro::Pendente ? null : now(),
    ]);
}

/**
 * @param  array<string, mixed>  $dados
 * @return array<string, mixed>
 */
function dadosDelegados(array $dados = []): array
{
    return [
        'tipo' => 'despesa',
        'descricao' => 'Mercado',
        'valor' => '150,00',
        'data' => '2026-11-20',
        'categoria_id' => test()->categoriaDoDono->id,
        'carteira_id' => test()->principal->id,
        ...$dados,
    ];
}

function lancamentoDoDono(array $lancamento = [], ?Carteira $carteira = null): Lancamento
{
    return lancamentoComCota(test()->dono, $carteira ?? test()->principal, test()->categoriaDoDono, ['data' => '2026-11-20', 'valor' => 5000, ...$lancamento]);
}

/**
 * The first expense item of the full-mode list of a wallet, as the user sees it.
 *
 * @return array<string, mixed>
 */
function itemNaTela(User $user, Carteira $carteira): array
{
    $item = [];
    test()->actingAs($user)->get(route('home', ['carteira' => $carteira->id]))
        ->assertInertia(function (Assert $page) use (&$item) {
            $item = $page->toArray()['props']['lancamentos']['despesa']['avista'][0];
        });

    return $item;
}

it('CA-203.1 shows the delegated wallet with the owner band, balances and list', function () {
    lancamentoDoDono(['descricao' => 'Luz', 'data' => '2026-11-10'], null);

    $this->actingAs($this->membro)->get(route('home', ['carteira' => $this->principal->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inicio')
            ->where('carteira.id', $this->principal->id)
            ->where('delegacao', ['donoId' => $this->dono->id, 'donoNome' => 'Carlos'])
            ->where('saldos.mes.estimado', -5000)
            ->where('lancamentos.despesa.avista.0.descricao', 'Luz')
            ->where('carteiras', fn ($carteiras) => collect($carteiras)->pluck('id')->all() === [$this->principal->id])
            ->where('categorias', fn ($categorias) => collect($categorias)->pluck('id')->contains($this->categoriaDoDono->id)
                && ! collect($categorias)->pluck('id')->contains($this->categoriaDaAna->id))
            ->where('contatos', fn ($contatos) => collect($contatos)->pluck('id')->all() === [$this->membro->id]));
});

it('CA-203.2 does not change the member last wallet when opening the delegated one', function () {
    $this->membro->forceFill(['ultima_carteira_id' => $this->daAna->id])->save();

    $this->actingAs($this->membro)->get(route('home', ['carteira' => $this->principal->id]))->assertOk();

    expect($this->membro->refresh()->ultima_carteira_id)->toBe($this->daAna->id);
});

it('CA-203.3 keeps the delegated wallet out of the member own screen and report', function () {
    $this->actingAs($this->membro)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('delegacao', null)
            ->where('carteiras', fn ($carteiras) => collect($carteiras)->pluck('id')->all() === [$this->daAna->id])
            ->where('destaques.saldosCarteiras', fn ($saldos) => ! collect($saldos)->has($this->principal->id)));

    $this->actingAs($this->membro)->get(route('relatorio', ['carteira' => $this->principal->id]))->assertForbidden();
});

it('CA-203.4 forbids the wallet without an accepted delegation of an active owner', function (string $caso) {
    match ($caso) {
        'pendente' => $this->delegacao->update(['status' => StatusMembro::Pendente]),
        'recusado' => $this->delegacao->update(['status' => StatusMembro::Recusado]),
        'ex-membro' => $this->delegacao->delete(),
        'dono desativado' => $this->dono->forceFill(['desativado_em' => now()])->save(),
    };

    $this->actingAs($this->membro)->get(route('home', ['carteira' => $this->principal->id]))->assertForbidden();
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosDelegados())->assertSessionHasErrors('carteira_id');
})->with(['pendente', 'recusado', 'ex-membro', 'dono desativado']);

it('CA-203.4 forbids a card even with an accepted member record', function () {
    $cartao = cartaoDe($this->dono, 'Nubank', $this->principal);
    delegarCarteira($cartao, $this->membro);

    $this->actingAs($this->membro)->get(route('home', ['carteira' => $cartao->id]))->assertForbidden();
});

it('CA-203.6 forbids the member from managing the delegated wallet', function (string $acao) {
    $resposta = match ($acao) {
        'renomear' => $this->actingAs($this->membro)->put(route('carteiras.update', $this->principal), ['nome' => 'Minha']),
        'excluir' => $this->actingAs($this->membro)->delete(route('carteiras.destroy', $this->principal)),
        'tornar padrão' => $this->actingAs($this->membro)->post(route('carteiras.padrao', $this->principal)),
        'convidar' => $this->actingAs($this->membro)->post(route('carteiras.membros.store', $this->principal), ['user_id' => $this->dono->id]),
    };

    $resposta->assertForbidden();
    expect($this->principal->refresh()->nome)->toBe('Principal');
})->with(['renomear', 'excluir', 'tornar padrão', 'convidar']);

it('CA-203.7 opens without tour and wallet balances, with the owner pending and spending', function () {
    lancamentoDoDono(['descricao' => 'Atrasada', 'data' => '2026-11-10']);

    $this->actingAs($this->membro)->get(route('home', ['carteira' => $this->principal->id, 'tutorial' => '1']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('mostrarTutorial', false)
            ->where('destaques.saldosCarteiras', null)
            ->where('destaques.vencidas.quantidade', 1)
            ->where('destaques.gastosDoMes.0.valor', 5000));
});

it('CA-203.8 follows the member simple mode, not the owner one', function () {
    $this->membro->forceFill(['modo_simples' => true])->save();
    lancamentoDoDono();

    $this->actingAs($this->membro)->get(route('home', ['carteira' => $this->principal->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('lancamentos.despesa', 1)
            ->missing('lancamentos.despesa.avista')
            ->where('destaques', null));
});

it('CA-204.1 creates the entry for the owner without changing the member balances', function () {
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosDelegados())->assertSessionHasNoErrors();

    $lancamento = Lancamento::query()->sole();

    expect($lancamento->user_id)->toBe($this->dono->id)
        ->and($lancamento->cotas()->sole())
        ->user_id->toBe($this->dono->id)
        ->carteira_id->toBe($this->principal->id)
        ->and($this->membro->cotas()->count())->toBe(0);
});

it('CA-204.2 edits, marks executed and deletes an entry of the delegated wallet', function () {
    $lancamento = lancamentoDoDono();
    $cota = $lancamento->cotas()->sole();

    $this->actingAs($this->membro)->put(route('lancamentos.update', $lancamento), dadosDelegados(['descricao' => 'Feira']))->assertSessionHasNoErrors();
    $this->actingAs($this->membro)->patch(route('cotas.executado', $cota), ['executado' => true])->assertSessionHasNoErrors();

    expect($lancamento->refresh()->descricao)->toBe('Feira')
        ->and($lancamento->user_id)->toBe($this->dono->id)
        ->and($cota->refresh()->executado)->toBeTrue();

    $this->actingAs($this->membro)->delete(route('lancamentos.destroy', $lancamento))->assertSessionHasNoErrors();

    expect(Lancamento::query()->count())->toBe(0);
});

it('CA-204.2 forbids entries of the owner outside the delegated wallets', function () {
    $lancamento = lancamentoDoDono(carteira: $this->reserva);

    $this->actingAs($this->membro)->put(route('lancamentos.update', $lancamento), dadosDelegados(['carteira_id' => $this->reserva->id]))->assertForbidden();
    $this->actingAs($this->membro)->delete(route('lancamentos.destroy', $lancamento))->assertForbidden();
    $this->actingAs($this->membro)->patch(route('cotas.executado', $lancamento->cotas()->sole()), ['executado' => true])->assertForbidden();
});

it('CA-204.3 accepts only categories of the owner and forbids the owner category routes', function () {
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosDelegados(['categoria_id' => $this->categoriaDaAna->id]))
        ->assertSessionHasErrors(['categoria_id' => 'Selecione uma categoria válida.']);

    $this->actingAs($this->membro)->put(route('categorias.update', $this->categoriaDoDono), ['nome' => 'Outra', 'tipo' => 'despesa'])->assertForbidden();
    $this->actingAs($this->membro)->delete(route('categorias.destroy', $this->categoriaDoDono))->assertForbidden();
});

it('CA-204.4 refuses a wallet not delegated to the member', function () {
    $lancamento = lancamentoDoDono();

    $this->actingAs($this->membro)->put(route('lancamentos.update', $lancamento), dadosDelegados(['carteira_id' => $this->reserva->id]))
        ->assertSessionHasErrors(['carteira_id' => 'Selecione uma carteira válida.']);
    $this->actingAs($this->membro)->put(route('lancamentos.update', $lancamento), dadosDelegados(['carteira_id' => $this->daAna->id]))
        ->assertSessionHasErrors(['carteira_id' => 'Selecione uma carteira válida.']);

    expect($lancamento->cotas()->sole()->carteira_id)->toBe($this->principal->id);
});

it('CA-204.4 moves the entry to another wallet of the owner delegated to the member', function () {
    delegarCarteira($this->reserva, $this->membro);
    $lancamento = lancamentoDoDono();

    $this->actingAs($this->membro)->put(route('lancamentos.update', $lancamento), dadosDelegados(['carteira_id' => $this->reserva->id]))->assertSessionHasNoErrors();

    expect($lancamento->cotas()->sole()->carteira_id)->toBe($this->reserva->id);
});

it('CA-204.5 uploads, downloads and removes the attachment of an entry of the wallet', function () {
    Storage::fake('local');
    $lancamento = lancamentoDoDono();

    $this->actingAs($this->membro)->post(route('lancamentos.update', $lancamento), [
        ...dadosDelegados(),
        '_method' => 'PUT',
        'anexo' => UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    expect($lancamento->refresh()->anexo_nome)->toBe('nota.pdf');
    $this->actingAs($this->membro)->get(route('lancamentos.anexo.show', $lancamento))->assertOk();

    $this->actingAs($this->membro)->delete(route('lancamentos.anexo.destroy', $lancamento))->assertSessionHasNoErrors();

    expect($lancamento->refresh()->anexo_caminho)->toBeNull();
});

it('CA-204.6 CA-204.7 groups with an owner group and runs the owner groups', function () {
    $cotaA = lancamentoDoDono()->cotas()->sole();
    $cotaB = lancamentoDoDono()->cotas()->sole();

    $this->actingAs($this->membro)->post(route('grupos.agrupar'), ['cotas' => [$cotaA->id, $cotaB->id], 'nome' => 'Casa'])->assertSessionHasNoErrors();

    $grupo = Grupo::query()->sole();

    expect($grupo->user_id)->toBe($this->dono->id)
        ->and($grupo->carteira_id)->toBe($this->principal->id);

    $this->actingAs($this->membro)->patch(route('grupos.executado', $grupo), ['executado' => true, 'cotas' => [$cotaA->id, $cotaB->id]])->assertSessionHasNoErrors();
    $this->actingAs($this->membro)->patch(route('grupos.update', $grupo), ['nome' => 'Lar'])->assertSessionHasNoErrors();
    $this->actingAs($this->membro)->post(route('grupos.desagrupar'), ['cotas' => [$cotaA->id]])->assertSessionHasNoErrors();

    expect($cotaB->refresh()->executado)->toBeTrue()
        ->and($grupo->refresh()->nome)->toBe('Lar')
        ->and($cotaA->refresh()->grupo_id)->toBeNull();

    $this->actingAs($this->membro)->delete(route('grupos.destroy', $grupo))->assertSessionHasNoErrors();

    expect(Grupo::query()->count())->toBe(0);
});

it('CA-204.6 refuses grouping shares of a wallet not delegated to the member', function () {
    $cota = lancamentoDoDono(carteira: $this->reserva)->cotas()->sole();

    $this->actingAs($this->membro)->post(route('grupos.agrupar'), ['cotas' => [$cota->id], 'nome' => 'Casa'])
        ->assertSessionHasErrors(['cotas.0' => 'Selecione itens seus.']);
});

it('CA-205.1 records the member who created the entry and shows it to the owner', function () {
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosDelegados())->assertSessionHasNoErrors();

    expect(Lancamento::query()->sole())
        ->criado_por_id->toBe($this->membro->id)
        ->criado_por_nome->toBe('Ana')
        ->alterado_por_id->toBeNull();

    expect(itemNaTela($this->dono, $this->principal)['registro'])->toBe(['lancadoPor' => 'Ana', 'alteradoPor' => null, 'alteradoEm' => null])
        ->and(itemNaTela($this->membro, $this->principal)['registro']['lancadoPor'])->toBe('Ana');
});

it('CA-205.2 records the member who changed the entry or its executed checkbox', function (string $acao) {
    $lancamento = lancamentoDoDono();
    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00'));

    match ($acao) {
        'edição' => $this->actingAs($this->membro)->put(route('lancamentos.update', $lancamento), dadosDelegados()),
        'executado' => $this->actingAs($this->membro)->patch(route('cotas.executado', $lancamento->cotas()->sole()), ['executado' => true]),
    };

    expect($lancamento->refresh())
        ->alterado_por_id->toBe($this->membro->id)
        ->alterado_por_nome->toBe('Ana')
        ->alterado_para_id->toBe($this->dono->id)
        ->and($lancamento->alterado_em?->toDateString())->toBe('2026-11-16')
        ->and(itemNaTela($this->dono, $this->principal)['registro'])->toBe(['lancadoPor' => null, 'alteradoPor' => 'Ana', 'alteradoEm' => '2026-11-16']);
})->with(['edição', 'executado']);

it('CA-205.3 clears the change record when the owner changes the entry later', function () {
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosDelegados());
    $lancamento = Lancamento::query()->sole();
    $this->actingAs($this->membro)->put(route('lancamentos.update', $lancamento), dadosDelegados(['descricao' => 'Feira']));

    $this->actingAs($this->dono)->patch(route('cotas.executado', $lancamento->cotas()->sole()), ['executado' => true]);

    expect($lancamento->refresh())
        ->alterado_por_id->toBeNull()
        ->alterado_por_nome->toBeNull()
        ->alterado_em->toBeNull()
        ->alterado_para_id->toBeNull()
        ->criado_por_nome->toBe('Ana');
});

it('CA-205.4 keeps the names after the member is removed or deletes the account', function () {
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosDelegados());
    $lancamento = Lancamento::query()->sole();
    $this->actingAs($this->membro)->put(route('lancamentos.update', $lancamento), dadosDelegados());

    $this->delegacao->delete();
    $this->membro->delete();

    expect(itemNaTela($this->dono, $this->principal)['registro'])
        ->lancadoPor->toBe('Ana')
        ->alteradoPor->toBe('Ana');
});

it('CA-205.5 CA-205.7 records the member on every installment of a new series but not on later generated ones', function () {
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosSerie($this->principal, $this->categoriaDoDono, ['total_parcelas' => 3]))->assertSessionHasNoErrors();

    $serie = Parcelamento::query()->sole();

    expect($serie->user_id)->toBe($this->dono->id)
        ->and($serie->lancamentos()->pluck('criado_por_nome')->all())->toBe(['Ana', 'Ana', 'Ana']);

    $this->actingAs($this->dono)->patch(route('parcelamentos.total', $serie), ['total_parcelas' => 4])->assertSessionHasNoErrors();

    expect($serie->lancamentos()->where('numero_parcela', 4)->value('criado_por_id'))->toBeNull();
});

it('CA-205.7 records the conversion as a change of the entry and the new installments as created by the member', function () {
    $lancamento = lancamentoDoDono();

    $this->actingAs($this->membro)->put(route('lancamentos.update', $lancamento), dadosDelegados([
        'repeticao' => 'parcelado', 'periodicidade' => 'mensal', 'total_parcelas' => 2, 'modo_valor' => 'parcela',
    ]))->assertSessionHasNoErrors();

    expect($lancamento->refresh())
        ->criado_por_id->toBeNull()
        ->alterado_por_nome->toBe('Ana')
        ->and(Lancamento::query()->where('numero_parcela', 2)->value('criado_por_nome'))->toBe('Ana');
});

it('CA-205.8 records running a group, but not grouping', function () {
    $cota = lancamentoDoDono()->cotas()->sole();

    $this->actingAs($this->membro)->post(route('grupos.agrupar'), ['cotas' => [$cota->id], 'nome' => 'Casa']);

    expect($cota->lancamento->refresh()->alterado_por_id)->toBeNull();

    $this->actingAs($this->membro)->patch(route('grupos.executado', Grupo::query()->sole()), ['executado' => true, 'cotas' => [$cota->id]]);

    expect($cota->lancamento->refresh()->alterado_por_nome)->toBe('Ana');
});

it('CA-205.9 hides the record from a participant of an entry the member created for the owner', function () {
    $contato = User::factory()->create();
    $doContato = Carteira::factory()->for($contato)->padrao()->create();
    Contato::factory()->aceito()->create(['solicitante_id' => $this->dono->id, 'convidado_id' => $contato->id]);

    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosRateio($this->principal, $this->categoriaDoDono, 'iguais', [
        ['user_id' => $this->dono->id], ['user_id' => $contato->id],
    ]))->assertSessionHasNoErrors();
    $this->actingAs($this->membro)->put(route('lancamentos.update', Lancamento::query()->sole()), dadosRateio($this->principal, $this->categoriaDoDono, 'iguais', [
        ['user_id' => $this->dono->id], ['user_id' => $contato->id],
    ], ['descricao' => 'Janta']))->assertSessionHasNoErrors();

    expect(itemNaTela($contato, $doContato)['registro'])->toBeNull()
        ->and(itemNaTela($this->dono, $this->principal)['registro'])->toBe(['lancadoPor' => 'Ana', 'alteradoPor' => 'Ana', 'alteradoEm' => '2026-11-14']);
});

it('CA-205.6 shows no record for an entry only the owner created and changed', function () {
    $this->actingAs($this->dono)->post(route('lancamentos.store'), dadosDelegados());

    expect(itemNaTela($this->dono, $this->principal)['registro'])->toBeNull();
});

describe('a contact entry with a share of the owner', function () {
    beforeEach(function () {
        $this->contato = User::factory()->create(['name' => 'Xavier']);
        $this->outro = User::factory()->create(['name' => 'Yara']);
        $this->doContato = Carteira::factory()->for($this->contato)->padrao()->create();
        $this->daYara = Carteira::factory()->for($this->outro)->padrao()->create();
        $categoriaDoContato = Categoria::factory()->for($this->contato)->create(['tipo' => TipoCategoria::Despesa]);
        $this->doX = lancamentoComCota($this->contato, $this->doContato, $categoriaDoContato, ['data' => '2026-11-20', 'valor' => 9000, 'modo_rateio' => 'iguais'], ['valor' => 3000]);
        $this->cotaDoDono = Cota::factory()->for($this->doX)->create(['user_id' => $this->dono->id, 'carteira_id' => $this->principal->id, 'categoria_id' => $this->categoriaDoDono->id, 'valor' => 3000]);
        $this->cotaDaYara = Cota::factory()->for($this->doX)->create(['user_id' => $this->outro->id, 'carteira_id' => $this->daYara->id, 'valor' => 3000]);
    });

    it('CA-206.2 CA-205.11 changes the owner share for the owner, shown only to the owner', function () {
        $outraCategoria = Categoria::factory()->for($this->dono)->create(['tipo' => TipoCategoria::Despesa]);

        $this->actingAs($this->membro)->put(route('cotas.update', $this->cotaDoDono), [
            'carteira_id' => $this->principal->id, 'categoria_id' => $outraCategoria->id, 'executado' => true,
        ])->assertSessionHasNoErrors();

        expect($this->cotaDoDono->refresh())
            ->categoria_id->toBe($outraCategoria->id)
            ->executado->toBeTrue()
            ->and($this->doX->refresh()->alterado_para_id)->toBe($this->dono->id)
            ->and(itemNaTela($this->dono, $this->principal)['registro']['alteradoPor'])->toBe('Ana')
            ->and(itemNaTela($this->contato, $this->doContato)['registro'])->toBeNull();
    });

    it('CA-205.11 keeps the record when another participant changes their share and clears it when the author does', function () {
        $this->actingAs($this->membro)->patch(route('cotas.executado', $this->cotaDoDono), ['executado' => true]);

        $this->actingAs($this->outro)->patch(route('cotas.executado', $this->cotaDaYara), ['executado' => true]);

        expect($this->doX->refresh()->alterado_por_nome)->toBe('Ana');

        $this->actingAs($this->contato)->put(route('lancamentos.update', $this->doX), [
            'tipo' => 'despesa', 'descricao' => 'Pizza', 'valor' => '90,00', 'data' => '2026-11-20',
            'categoria_id' => $this->doX->cotas()->where('user_id', $this->contato->id)->value('categoria_id'),
            'carteira_id' => $this->doContato->id,
            'rateio' => ['modo' => 'iguais', 'participantes' => [['user_id' => $this->contato->id], ['user_id' => $this->dono->id], ['user_id' => $this->outro->id]]],
        ])->assertSessionHasNoErrors();

        expect($this->doX->refresh()->alterado_por_nome)->toBeNull();
    });

    it('CA-206.2 CA-206.4 leaves the split for the owner and names the owner removed', function () {
        $this->actingAs($this->membro)->delete(route('cotas.destroy', $this->cotaDoDono))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Você removeu Carlos da divisão.');

        expect($this->doX->cotas()->where('user_id', $this->dono->id)->exists())->toBeFalse();
    });
});

it('CA-206.1 offers the owner contacts and puts the member own share in the member default wallet', function () {
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosRateio($this->principal, $this->categoriaDoDono, 'iguais', [
        ['user_id' => $this->dono->id], ['user_id' => $this->membro->id],
    ]))->assertSessionHasNoErrors()->assertRedirect();

    $lancamento = Lancamento::query()->sole();

    expect($lancamento->user_id)->toBe($this->dono->id)
        ->and($lancamento->cotas()->where('user_id', $this->membro->id)->value('carteira_id'))->toBe($this->daAna->id);

    $this->actingAs($this->membro)->get(route('recebidas.index'))
        ->assertInertia(fn (Assert $page) => $page->where('recebidas.data.0.autor', 'Carlos'));
});

it('CA-206.1 refuses a contact of the member who is not a contact of the owner', function () {
    $daAna = User::factory()->create();
    Contato::factory()->aceito()->create(['solicitante_id' => $this->membro->id, 'convidado_id' => $daAna->id]);

    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosRateio($this->principal, $this->categoriaDoDono, 'iguais', [
        ['user_id' => $this->dono->id], ['user_id' => $daAna->id],
    ]))->assertSessionHasErrors(['rateio' => 'Só é possível dividir com contatos ativos.']);
});

it('CA-206.3 leaves only installments of the delegated wallets and keeps the owner in the model', function (bool $padraoDelegada) {
    $contato = User::factory()->create();
    $doContato = Carteira::factory()->for($contato)->padrao()->create();
    Contato::factory()->aceito()->create(['solicitante_id' => $this->dono->id, 'convidado_id' => $contato->id]);
    $categoria = Categoria::factory()->for($contato)->create(['tipo' => TipoCategoria::Despesa]);
    $this->actingAs($contato)->post(route('lancamentos.store'), dadosSerie($doContato, $categoria, [
        'total_parcelas' => 3,
        'rateio' => ['modo' => 'iguais', 'participantes' => [['user_id' => $contato->id], ['user_id' => $this->dono->id]]],
    ]))->assertSessionHasNoErrors();
    $serie = Parcelamento::query()->sole();
    $cotasDoDono = Cota::query()->where('cotas.user_id', $this->dono->id)->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')->orderBy('numero_parcela')->select('cotas.*')->get();
    Cota::query()->whereKey($cotasDoDono[2]->id)->update(['carteira_id' => $this->reserva->id]);

    if (! $padraoDelegada) {
        $this->principal->update(['padrao' => false]);
        $this->reserva->update(['padrao' => true]);
    }

    $this->actingAs($this->membro)->delete(route('cotas.destroy', $cotasDoDono[0]), ['escopo' => 'todas'])->assertSessionHasNoErrors();

    expect(Cota::query()->where('user_id', $this->dono->id)->pluck('id')->all())->toBe([$cotasDoDono[2]->id])
        ->and($serie->cotas()->where('user_id', $this->dono->id)->exists())->toBe(! $padraoDelegada);
})->with(['padrão delegada' => true, 'padrão fora' => false]);

it('CA-207.1 CA-207.4 changes only installments of delegated wallets and the model only when its wallet is delegated', function (bool $modeloDelegado) {
    $this->actingAs($this->dono)->post(route('lancamentos.store'), dadosSerie($modeloDelegado ? $this->principal : $this->reserva, $this->categoriaDoDono, ['total_parcelas' => 3]))->assertSessionHasNoErrors();
    $serie = Parcelamento::query()->sole();
    $parcelas = $serie->lancamentos()->orderBy('numero_parcela')->get();
    $foraId = $modeloDelegado ? $parcelas[2]->id : $parcelas[1]->id;
    Cota::query()->whereIn('lancamento_id', $parcelas->modelKeys())->update(['carteira_id' => $this->principal->id]);
    Cota::query()->where('lancamento_id', $foraId)->update(['carteira_id' => $this->reserva->id]);
    $aberta = $parcelas[0];

    $this->actingAs($this->membro)->put(route('lancamentos.update', $aberta), [
        ...dadosDelegados(['descricao' => 'TV nova', 'valor' => '100,00', 'data' => $aberta->data->toDateString()]),
        'escopo' => 'todas',
    ])->assertSessionHasNoErrors();

    expect(Lancamento::query()->whereKey($foraId)->value('descricao'))->toBe('TV')
        ->and(Lancamento::query()->whereKey($aberta->id)->value('descricao'))->toBe('TV nova')
        ->and($serie->refresh()->descricao)->toBe($modeloDelegado ? 'TV nova' : 'TV');
})->with(['modelo delegado' => true, 'modelo fora' => false]);

it('CA-207.1 CA-207.4 deletes only installments of delegated wallets and does not end a series modelled elsewhere', function () {
    $this->actingAs($this->dono)->post(route('lancamentos.store'), dadosSerie($this->reserva, $this->categoriaDoDono, [
        'repeticao' => 'recorrente', 'total_parcelas' => null, 'modo_valor' => null, 'valor' => '50,00',
    ]))->assertSessionHasNoErrors();
    $serie = Parcelamento::query()->sole();
    $primeira = $serie->lancamentos()->orderBy('numero_parcela')->first();
    $primeira->cotas()->update(['carteira_id' => $this->principal->id]);
    $total = $serie->lancamentos()->count();

    $this->actingAs($this->membro)->delete(route('lancamentos.destroy', $primeira), ['escopo' => 'proximas'])->assertSessionHasNoErrors();

    expect($serie->lancamentos()->count())->toBe($total - 1)
        ->and($serie->refresh()->encerrado_em)->toBeNull();
});

it('CA-207.2 forbids the series tracking unless the member has an own share in it', function () {
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosSerie($this->principal, $this->categoriaDoDono, ['total_parcelas' => 2]))->assertSessionHasNoErrors();
    $serie = Parcelamento::query()->sole();

    $this->actingAs($this->membro)->get(route('parcelamentos.show', $serie))->assertForbidden();

    $serie->lancamentos->each(fn (Lancamento $parcela) => Cota::factory()->for($parcela)->create(['user_id' => $this->membro->id, 'carteira_id' => $this->daAna->id, 'valor' => 1]));

    $this->actingAs($this->membro)->get(route('parcelamentos.show', $serie))->assertOk();
});

it('CA-207.3 forbids ending a series and changing its total', function () {
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosSerie($this->principal, $this->categoriaDoDono, ['total_parcelas' => 2]))->assertSessionHasNoErrors();
    $serie = Parcelamento::query()->sole();

    $this->actingAs($this->membro)->patch(route('parcelamentos.total', $serie), ['total_parcelas' => 5])->assertForbidden();
    $this->actingAs($this->membro)->post(route('parcelamentos.encerrar', $serie), ['data' => '2026-12-31'])->assertForbidden();

    expect($serie->refresh()->total_parcelas)->toBe(2);
});

it('CA-209.4 gives the access back when the owner is reactivated', function () {
    $this->dono->forceFill(['desativado_em' => now()])->save();

    $this->actingAs($this->membro)->get(route('home', ['carteira' => $this->principal->id]))->assertForbidden();

    $this->dono->forceFill(['desativado_em' => null])->save();

    $this->actingAs($this->membro)->get(route('home', ['carteira' => $this->principal->id]))->assertOk();
});
