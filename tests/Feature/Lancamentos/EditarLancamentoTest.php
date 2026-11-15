<?php

use App\Enums\ModoRateio;
use App\Enums\TipoCategoria;
use App\Enums\TipoLancamento;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->user = User::factory()->create();
    $this->carteira = Carteira::factory()->for($this->user)->padrao()->create();
    $this->despesa = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Despesa]);
    $this->lancamento = lancamentoComCota($this->user, $this->carteira, $this->despesa, ['valor' => 10000, 'data' => '2026-11-20']);
});

it('CA-041.1 updates every field and the single share follows', function () {
    $outraCarteira = Carteira::factory()->for($this->user)->create();
    $ambos = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Ambos]);

    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $this->lancamento), [
            'tipo' => 'receita',
            'descricao' => 'Reembolso',
            'valor' => '250,00',
            'data' => '2026-11-21',
            'categoria_id' => $ambos->id,
            'carteira_id' => $outraCarteira->id,
            'observacao' => 'Empresa',
            'executado' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect($this->lancamento->fresh())
        ->tipo->toBe(TipoLancamento::Receita)
        ->descricao->toBe('Reembolso')
        ->valor->toBe(25000)
        ->observacao->toBe('Empresa')
        ->and($this->lancamento->cotas()->sole())
        ->valor->toBe(25000)
        ->carteira_id->toBe($outraCarteira->id)
        ->categoria_id->toBe($ambos->id)
        ->executado->toBeTrue()
        ->executado_em->not->toBeNull();
});

it('CA-041.2 rejects a type change that makes the category incompatible', function () {
    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $this->lancamento), [
            'tipo' => 'receita',
            'descricao' => 'Mercado',
            'valor' => '100,00',
            'data' => '2026-11-20',
            'categoria_id' => $this->despesa->id,
            'carteira_id' => $this->carteira->id,
        ])
        ->assertSessionHasErrors('categoria_id');

    expect($this->lancamento->fresh()->tipo)->toBe(TipoLancamento::Despesa);
});

it('CA-041.3 forbids editing an entry of another user', function () {
    $outro = User::factory()->create();

    $this->actingAs($outro)
        ->put(route('lancamentos.update', $this->lancamento), ['descricao' => 'Meu'])
        ->assertForbidden();
});

it('CA-042.1 CA-042.2 marks and unmarks a share as executed', function () {
    $cota = $this->lancamento->cotas()->sole();

    $this->actingAs($this->user)->patch(route('cotas.executado', $cota), ['executado' => true])->assertSessionHasNoErrors();

    expect($cota->fresh())->executado->toBeTrue()
        ->and($cota->fresh()->executado_em->toDateTimeString())->toBe('2026-11-14 10:00:00');

    $this->actingAs($this->user)->patch(route('cotas.executado', $cota), ['executado' => false])->assertSessionHasNoErrors();

    expect($cota->fresh())->executado->toBeFalse()->executado_em->toBeNull();
});

it('CA-042.3 forbids changing the status of a share of another user', function () {
    $outro = User::factory()->create();

    $this->actingAs($outro)
        ->patch(route('cotas.executado', $this->lancamento->cotas()->sole()), ['executado' => true])
        ->assertForbidden();
});

it('CA-043.1 deletes the entry with its share and attachment file', function () {
    Storage::fake('local');
    $this->actingAs($this->user)->put(route('lancamentos.update', $this->lancamento), [
        'tipo' => 'despesa',
        'descricao' => 'Mercado',
        'valor' => '100,00',
        'data' => '2026-11-20',
        'categoria_id' => $this->despesa->id,
        'carteira_id' => $this->carteira->id,
        'anexo' => UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf'),
    ]);
    $caminho = $this->lancamento->fresh()->anexo_caminho;

    $this->actingAs($this->user)->delete(route('lancamentos.destroy', $this->lancamento))->assertSessionHasNoErrors();

    expect($this->lancamento->fresh())->toBeNull()
        ->and(Cota::query()->where('lancamento_id', $this->lancamento->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($caminho);
});

it('CA-043.2 forbids deleting an entry of another user', function () {
    $outro = User::factory()->create();

    $this->actingAs($outro)->delete(route('lancamentos.destroy', $this->lancamento))->assertForbidden();

    expect($this->lancamento->fresh())->not->toBeNull();
});

it('refuses editing a shared entry through the simple form until sharing rules exist', function () {
    $this->lancamento->forceFill(['modo_rateio' => ModoRateio::Iguais])->save();

    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $this->lancamento), [
            'tipo' => 'despesa',
            'descricao' => 'Outro nome',
            'valor' => '100,00',
            'data' => '2026-11-20',
            'categoria_id' => $this->despesa->id,
            'carteira_id' => $this->carteira->id,
        ])
        ->assertSessionHasErrors('lancamento');

    expect($this->lancamento->fresh()->descricao)->not->toBe('Outro nome');
});

it('CA-043.1 blocks deleting a shared entry when a participant executed their share (RN-086)', function () {
    $this->lancamento->forceFill(['modo_rateio' => ModoRateio::Iguais])->save();
    $participante = Cota::factory()->for($this->lancamento)->executado()->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($this->user)
        ->delete(route('lancamentos.destroy', $this->lancamento))
        ->assertSessionHasErrors('lancamento');

    expect($this->lancamento->fresh())->not->toBeNull();

    $participante->forceFill(['executado' => false, 'executado_em' => null])->save();

    $this->actingAs($this->user)
        ->delete(route('lancamentos.destroy', $this->lancamento))
        ->assertSessionHasNoErrors();

    expect($this->lancamento->fresh())->toBeNull();
});

it('refuses editing or deleting an installment through the simple form until series rules exist', function () {
    $serie = Parcelamento::factory()->for($this->user)->create(['carteira_id' => $this->carteira->id, 'categoria_id' => $this->despesa->id]);
    $this->lancamento->forceFill(['parcelamento_id' => $serie->id, 'numero_parcela' => 1])->save();

    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $this->lancamento), [
            'tipo' => 'despesa',
            'descricao' => 'Outro nome',
            'valor' => '100,00',
            'data' => '2026-11-20',
            'categoria_id' => $this->despesa->id,
            'carteira_id' => $this->carteira->id,
        ])
        ->assertSessionHasErrors('lancamento');
    $this->actingAs($this->user)
        ->delete(route('lancamentos.destroy', $this->lancamento))
        ->assertSessionHasErrors('lancamento');

    expect($this->lancamento->fresh())->not->toBeNull()
        ->descricao->not->toBe('Outro nome');
});
