<?php

use App\Enums\ModoRateio;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Contato;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriasGlobaisSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->seed(CategoriasGlobaisSeeder::class);
    $this->a = User::factory()->create(['name' => 'Ana']);
    $this->b = User::factory()->create(['name' => 'Bruno']);
    $this->c = User::factory()->create(['name' => 'Carla']);
    $this->carteiraA = Carteira::factory()->for($this->a)->padrao()->create();
    $this->carteiraB = Carteira::factory()->for($this->b)->padrao()->create();
    $this->outraB = Carteira::factory()->for($this->b)->create();
    $this->carteiraC = Carteira::factory()->for($this->c)->padrao()->create();
    Contato::factory()->aceito()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id]);
    Contato::factory()->aceito()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->c->id]);
    $this->mercado = Categoria::query()->globais()->where('nome', 'Mercado')->sole();
});

function ratear(User $autor, Carteira $carteira, Categoria $categoria, array $participantes, array $dados = [], string $modo = 'iguais'): Lancamento
{
    test()->actingAs($autor)
        ->post(route('lancamentos.store'), dadosRateio($carteira, $categoria, $modo, $participantes, $dados))
        ->assertSessionHasNoErrors();

    return $autor->lancamentos()->latest('id')->firstOrFail();
}

function editarRateio(User $autor, Lancamento $lancamento, Carteira $carteira, Categoria $categoria, array $participantes, array $dados = [], string $modo = 'iguais')
{
    return test()->actingAs($autor)->put(route('lancamentos.update', $lancamento), dadosRateio($carteira, $categoria, $modo, $participantes, [
        'descricao' => $lancamento->descricao,
        'data' => $lancamento->data->toDateString(),
        ...$dados,
    ]));
}

it('CA-071.1 CA-071.2 counts only the participant share and shows total and author', function () {
    ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);

    $this->actingAs($this->b)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('carteira.id', $this->carteiraB->id)
            ->where('saldos.mes.estimado', -5000)
            ->where('lancamentos.despesa.avista.0.valor', 5000)
            ->where('lancamentos.despesa.avista.0.total', 10000)
            ->where('lancamentos.despesa.avista.0.autor', 'Ana')
            ->where('lancamentos.despesa.avista.0.compartilhado', true)
            ->where('lancamentos.despesa.avista.0.edicao', null)
            ->where('lancamentos.despesa.avista.0.participacao.carteiraId', $this->carteiraB->id));
});

it('CA-071.3 lets the participant change wallet, category and status of their own share only', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);
    $cotaB = $lancamento->cotas()->where('user_id', $this->b->id)->sole();
    $propria = Categoria::factory()->for($this->b)->create();

    $this->actingAs($this->b)
        ->put(route('cotas.update', $cotaB), ['carteira_id' => $this->outraB->id, 'categoria_id' => $propria->id, 'executado' => true])
        ->assertSessionHasNoErrors();

    expect($cotaB->fresh())->carteira_id->toBe($this->outraB->id)->categoria_id->toBe($propria->id)->executado->toBeTrue()
        ->and($lancamento->cotas()->where('user_id', $this->a->id)->sole())->carteira_id->toBe($this->carteiraA->id)->executado->toBeFalse();

    $this->actingAs($this->b)
        ->put(route('cotas.update', $cotaB), ['carteira_id' => $this->carteiraA->id, 'categoria_id' => $propria->id, 'executado' => true])
        ->assertSessionHasErrors('carteira_id');
    $this->actingAs($this->c)
        ->put(route('cotas.update', $cotaB), ['carteira_id' => $this->carteiraC->id, 'categoria_id' => $this->mercado->id, 'executado' => true])
        ->assertForbidden();
});

it('forbids the author to change their own share through the participation form', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);
    $outraA = Carteira::factory()->for($this->a)->create();

    $this->actingAs($this->a)
        ->put(route('cotas.update', $lancamento->cotas()->where('user_id', $this->a->id)->sole()), [
            'carteira_id' => $outraA->id, 'categoria_id' => $this->mercado->id, 'executado' => true,
        ])
        ->assertForbidden();
});

it('CA-072.4 accepts the same participants in another order when a share is executed', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);
    $lancamento->cotas()->where('user_id', $this->b->id)->update(['executado' => true, 'executado_em' => now()]);

    editarRateio($this->a, $lancamento, $this->carteiraA, $this->mercado, [['user_id' => $this->b->id], ['user_id' => $this->a->id]], ['descricao' => 'Jantar de sexta'])
        ->assertSessionHasNoErrors();

    expect($lancamento->fresh()->descricao)->toBe('Jantar de sexta')
        ->and(valoresDasCotas($lancamento))->toBe([$this->a->id => 5000, $this->b->id => 5000]);
});

it('CA-071.4 forbids the participant to change the entry', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);

    editarRateio($this->b, $lancamento, $this->carteiraB, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]], ['valor' => '10,00'])
        ->assertForbidden();
    $this->actingAs($this->b)->delete(route('lancamentos.destroy', $lancamento))->assertForbidden();
});

it('CA-071.5 counts unseen received shares and marks them as seen when the list opens', function () {
    ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);
    ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]], ['descricao' => 'Cinema']);

    $this->actingAs($this->b)->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('contadores.recebidas', 2));

    $this->actingAs($this->b)->get(route('recebidas.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('recebidas/Index')
            ->has('recebidas.data', 2)
            ->where('recebidas.data.0.descricao', 'Cinema')
            ->where('recebidas.data.0.nova', true)
            ->where('recebidas.data.0.autor', 'Ana'));

    expect(Cota::query()->where('user_id', $this->b->id)->whereNull('vista_em')->count())->toBe(0);
    $this->actingAs($this->b)->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('contadores.recebidas', 0));
});

it('RN-144 marks as seen only the received shares on the open page', function () {
    foreach (range(1, 31) as $numero) {
        ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]], ['descricao' => "Conta {$numero}"]);
    }

    $this->actingAs($this->b)->get(route('recebidas.index'))->assertInertia(fn (Assert $page) => $page->has('recebidas.data', 30));

    $this->actingAs($this->b)->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('contadores.recebidas', 1));
    $this->actingAs($this->b)->get(route('recebidas.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->where('recebidas.data.0.descricao', 'Conta 1')->where('recebidas.data.0.nova', true));
});

it('CA-071.6 CA-044.4 lets the participant see the note and download the attachment', function () {
    Storage::fake('local');
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'iguais', [['user_id' => $this->a->id], ['user_id' => $this->b->id]], [
        'observacao' => 'Nota fiscal anexa',
        'anexo' => UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf'),
    ]))->assertSessionHasNoErrors();
    $lancamento = $this->a->lancamentos()->sole();

    $this->actingAs($this->b)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lancamentos.despesa.avista.0.participacao.observacao', 'Nota fiscal anexa')
            ->where('lancamentos.despesa.avista.0.participacao.anexoNome', 'nota.pdf'));
    $this->actingAs($this->b)->get(route('lancamentos.anexo.show', $lancamento))->assertOk();
});

it('CA-044.6 forbids the participant to attach or remove the attachment', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);

    $this->actingAs($this->b)->delete(route('lancamentos.anexo.destroy', $lancamento))->assertForbidden();
    editarRateio($this->b, $lancamento, $this->carteiraB, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]], [
        'anexo' => UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf'),
    ])->assertForbidden();
});

it('CA-072.1 recalculates shares when the author changes the amount', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);

    editarRateio($this->a, $lancamento, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]], ['valor' => '120,00'])
        ->assertSessionHasNoErrors();

    expect(valoresDasCotas($lancamento))->toBe([$this->a->id => 6000, $this->b->id => 6000]);
});

it('CA-072.2 adds a participant with a share in their default wallet, not seen yet', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);
    $cotaB = $lancamento->cotas()->where('user_id', $this->b->id)->sole();
    $cotaB->forceFill(['vista_em' => now(), 'carteira_id' => $this->outraB->id])->save();

    editarRateio($this->a, $lancamento, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id], ['user_id' => $this->c->id]])
        ->assertSessionHasNoErrors();

    $cotaC = $lancamento->cotas()->where('user_id', $this->c->id)->sole();

    expect(valoresDasCotas($lancamento))->toBe([$this->a->id => 3334, $this->b->id => 3333, $this->c->id => 3333])
        ->and($cotaC)->carteira_id->toBe($this->carteiraC->id)->vista_em->toBeNull()
        ->and($cotaB->fresh())->carteira_id->toBe($this->outraB->id)->vista_em->not->toBeNull();
});

it('CA-072.3 removes a participant and recalculates', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id], ['user_id' => $this->c->id]]);

    editarRateio($this->a, $lancamento, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->c->id]])
        ->assertSessionHasNoErrors();

    expect(valoresDasCotas($lancamento))->toBe([$this->a->id => 5000, $this->c->id => 5000]);
});

it('CA-072.4 blocks amount and split changes after a participant executed, but allows description, date and note', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);
    $lancamento->cotas()->where('user_id', $this->b->id)->update(['executado' => true, 'executado_em' => now()]);
    $participantes = [['user_id' => $this->a->id], ['user_id' => $this->b->id]];

    editarRateio($this->a, $lancamento, $this->carteiraA, $this->mercado, $participantes, ['valor' => '120,00'])
        ->assertSessionHasErrors(['rateio' => 'Bruno já marcou a cota como executada. Valor e divisão não podem mudar.']);
    editarRateio($this->a, $lancamento, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id], ['user_id' => $this->c->id]])
        ->assertSessionHasErrors('rateio');

    editarRateio($this->a, $lancamento, $this->carteiraA, $this->mercado, $participantes, [
        'descricao' => 'Jantar de aniversário', 'data' => '2026-11-21', 'observacao' => 'Restaurante X',
    ])->assertSessionHasNoErrors();

    expect($lancamento->fresh())->descricao->toBe('Jantar de aniversário')->observacao->toBe('Restaurante X')
        ->and(valoresDasCotas($lancamento))->toBe([$this->a->id => 5000, $this->b->id => 5000]);
});

it('CA-073.1 removes the last participant and the entry stops being shared', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);

    $this->actingAs($this->b)->delete(route('cotas.destroy', $lancamento->cotas()->where('user_id', $this->b->id)->sole()))->assertSessionHasNoErrors();

    expect(valoresDasCotas($lancamento))->toBe([$this->a->id => 10000])
        ->and($lancamento->fresh()->modo_rateio)->toBeNull();
});

it('CA-073.2 adds the leaving share to the author and keeps the entry shared', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id], ['user_id' => $this->c->id]]);

    $this->actingAs($this->c)->delete(route('cotas.destroy', $lancamento->cotas()->where('user_id', $this->c->id)->sole()))->assertSessionHasNoErrors();

    expect(valoresDasCotas($lancamento))->toBe([$this->a->id => 6667, $this->b->id => 3333])
        ->and($lancamento->fresh()->modo_rateio)->toBe(ModoRateio::Fixo)
        ->and($lancamento->fresh()->valor)->toBe(10000);
});

it('CA-073.5 keeps the shares of who stayed when the author edits after an equal split lost a participant', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id], ['user_id' => $this->c->id]]);
    $this->actingAs($this->c)->delete(route('cotas.destroy', $lancamento->cotas()->where('user_id', $this->c->id)->sole()))->assertSessionHasNoErrors();
    $lancamento->cotas()->where('user_id', $this->b->id)->update(['executado' => true, 'executado_em' => now()]);

    editarRateio($this->a, $lancamento->fresh(), $this->carteiraA, $this->mercado, [
        ['user_id' => $this->a->id, 'valor' => '66,67'], ['user_id' => $this->b->id, 'valor' => '33,33'],
    ], ['descricao' => 'Jantar de sexta'], 'fixo')->assertSessionHasNoErrors();

    expect($lancamento->fresh()->descricao)->toBe('Jantar de sexta')
        ->and(valoresDasCotas($lancamento))->toBe([$this->a->id => 6667, $this->b->id => 3333]);
});

it('CA-073.3 creates the author share when the author had none', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->b->id], ['user_id' => $this->c->id]]);

    $this->actingAs($this->b)->delete(route('cotas.destroy', $lancamento->cotas()->where('user_id', $this->b->id)->sole()))->assertSessionHasNoErrors();

    $cotaA = $lancamento->cotas()->where('user_id', $this->a->id)->sole();

    expect($cotaA)
        ->valor->toBe(5000)
        ->carteira_id->toBe($this->carteiraA->id)
        ->categoria_id->toBe(Categoria::outros()->id)
        ->executado->toBeFalse();
});

it('forbids the author to leave their own split', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);

    $this->actingAs($this->a)->delete(route('cotas.destroy', $lancamento->cotas()->where('user_id', $this->a->id)->sole()))->assertForbidden();
});

it('CA-074.1 deletes the entry with every share', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);

    $this->actingAs($this->a)->delete(route('lancamentos.destroy', $lancamento))->assertSessionHasNoErrors();

    expect($lancamento->fresh())->toBeNull()->and(Cota::query()->count())->toBe(0);
});

it('CA-074.2 blocks deletion naming the participant who executed', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->a->id], ['user_id' => $this->b->id]]);
    $lancamento->cotas()->where('user_id', $this->b->id)->update(['executado' => true, 'executado_em' => now()]);

    $this->actingAs($this->a)->delete(route('lancamentos.destroy', $lancamento))
        ->assertSessionHasErrors(['lancamento' => 'Bruno já marcou a cota como executada. O lançamento não pode ser excluído.']);

    expect($lancamento->fresh())->not->toBeNull();
});

it('CA-070.15 shows an entry without an author share in the author wallet without changing balances', function () {
    ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->b->id], ['user_id' => $this->c->id]]);

    $this->actingAs($this->a)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('saldos.mes.estimado', 0)
            ->where('lancamentos.despesa.avista.0.valor', 0)
            ->where('lancamentos.despesa.avista.0.total', 10000)
            ->where('lancamentos.despesa.avista.0.cotaId', null)
            ->where('lancamentos.despesa.avista.0.compartilhado', true)
            ->where('lancamentos.despesa.avista.0.edicao.rateio.autorParticipa', false));
});

it('RN-145 opens the form of an author without a share only with a category the author sees', function () {
    $lancamento = ratear($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->b->id], ['user_id' => $this->c->id]]);
    $pessoalB = Categoria::factory()->for($this->b)->create(['tipo' => 'despesa']);

    $this->actingAs($this->a)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('lancamentos.despesa.avista.0.edicao.categoriaId', $this->mercado->id));

    $lancamento->cotas()->where('user_id', $this->b->id)->update(['categoria_id' => $pessoalB->id]);

    $this->actingAs($this->a)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('lancamentos.despesa.avista.0.edicao.categoriaId', null));
});
