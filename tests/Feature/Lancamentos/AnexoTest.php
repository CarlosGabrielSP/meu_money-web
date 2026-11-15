<?php

use App\Enums\ModoRateio;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->carteira = Carteira::factory()->for($this->user)->padrao()->create();
    $this->categoria = Categoria::factory()->for($this->user)->create();
    $this->lancamento = lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-20']);
});

function enviarAnexo(User $user, $lancamento, Carteira $carteira, Categoria $categoria, UploadedFile $arquivo)
{
    return test()->actingAs($user)->put(route('lancamentos.update', $lancamento), [
        'tipo' => 'despesa',
        'descricao' => 'Mercado',
        'valor' => '100,00',
        'data' => '2026-11-20',
        'categoria_id' => $categoria->id,
        'carteira_id' => $carteira->id,
        'anexo' => $arquivo,
    ]);
}

it('CA-044.1 stores the attachment on the private disk', function () {
    enviarAnexo($this->user, $this->lancamento, $this->carteira, $this->categoria, UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf'))
        ->assertSessionHasNoErrors();

    $lancamento = $this->lancamento->fresh();

    expect($lancamento->anexo_nome)->toBe('nota.pdf')
        ->and($lancamento->anexo_caminho)->not->toBeNull();
    Storage::disk('local')->assertExists($lancamento->anexo_caminho);
});

it('CA-044.2 replaces the previous attachment and deletes its file', function () {
    enviarAnexo($this->user, $this->lancamento, $this->carteira, $this->categoria, UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf'));
    $anterior = $this->lancamento->fresh()->anexo_caminho;

    enviarAnexo($this->user, $this->lancamento, $this->carteira, $this->categoria, UploadedFile::fake()->image('recibo.png'))
        ->assertSessionHasNoErrors();

    $lancamento = $this->lancamento->fresh();

    expect($lancamento->anexo_nome)->toBe('recibo.png');
    Storage::disk('local')->assertMissing($anterior);
    Storage::disk('local')->assertExists($lancamento->anexo_caminho);
});

it('CA-044.3 rejects an invalid type or a file above 5 MB', function (UploadedFile $arquivo) {
    enviarAnexo($this->user, $this->lancamento, $this->carteira, $this->categoria, $arquivo)
        ->assertSessionHasErrors('anexo');

    expect($this->lancamento->fresh()->anexo_caminho)->toBeNull();
})->with([
    'texto' => fn () => UploadedFile::fake()->create('nota.txt', 10, 'text/plain'),
    'acima de 5 MB' => fn () => UploadedFile::fake()->create('nota.pdf', 5121, 'application/pdf'),
]);

it('CA-044.4 lets the author and participants download, never other users', function () {
    enviarAnexo($this->user, $this->lancamento, $this->carteira, $this->categoria, UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf'));
    $participante = User::factory()->create();
    Cota::factory()->for($this->lancamento)->create(['user_id' => $participante->id]);
    $outro = User::factory()->create();

    $this->actingAs($this->user)->get(route('lancamentos.anexo.show', $this->lancamento))->assertOk()->assertDownload('nota.pdf');
    $this->actingAs($participante)->get(route('lancamentos.anexo.show', $this->lancamento))->assertOk();
    $this->actingAs($outro)->get(route('lancamentos.anexo.show', $this->lancamento))->assertForbidden();
});

it('CA-044.5 removes the attachment file and clears its fields', function () {
    enviarAnexo($this->user, $this->lancamento, $this->carteira, $this->categoria, UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf'));
    $caminho = $this->lancamento->fresh()->anexo_caminho;

    $this->actingAs($this->user)->delete(route('lancamentos.anexo.destroy', $this->lancamento))->assertSessionHasNoErrors();

    expect($this->lancamento->fresh())->anexo_caminho->toBeNull()->anexo_nome->toBeNull();
    Storage::disk('local')->assertMissing($caminho);
});

it('CA-044.6 forbids a participant to attach or remove', function () {
    $participante = User::factory()->create();
    Cota::factory()->for($this->lancamento)->create(['user_id' => $participante->id]);

    enviarAnexo($participante, $this->lancamento, $this->carteira, $this->categoria, UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf'))
        ->assertForbidden();
    $this->actingAs($participante)->delete(route('lancamentos.anexo.destroy', $this->lancamento))->assertForbidden();
});

it('refuses removing the attachment of a shared entry until sharing rules exist', function () {
    enviarAnexo($this->user, $this->lancamento, $this->carteira, $this->categoria, UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf'));
    $caminho = $this->lancamento->fresh()->anexo_caminho;
    $this->lancamento->forceFill(['modo_rateio' => ModoRateio::Iguais])->save();

    $this->actingAs($this->user)->delete(route('lancamentos.anexo.destroy', $this->lancamento))->assertSessionHasErrors('lancamento');

    Storage::disk('local')->assertExists($caminho);
});

it('removes attachment files when the wallet contents or the account are deleted', function () {
    $carteira = Carteira::factory()->for($this->user)->create();
    $naCarteira = lancamentoComCota($this->user, $carteira, $this->categoria, ['data' => '2026-11-20']);
    enviarAnexo($this->user, $naCarteira, $carteira, $this->categoria, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
    $caminhoCarteira = $naCarteira->fresh()->anexo_caminho;
    enviarAnexo($this->user, $this->lancamento, $this->carteira, $this->categoria, UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'));
    $caminhoConta = $this->lancamento->fresh()->anexo_caminho;

    $this->actingAs($this->user)->delete(route('carteiras.destroy', $carteira), ['modo' => 'excluir'])->assertSessionHasNoErrors();

    Storage::disk('local')->assertMissing($caminhoCarteira);
    Storage::disk('local')->assertExists($caminhoConta);

    $this->actingAs($this->user)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();

    Storage::disk('local')->assertMissing($caminhoConta);
});
