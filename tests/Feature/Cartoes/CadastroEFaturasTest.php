<?php

use App\Enums\CompetenciaFatura;
use App\Enums\CorCarteira;
use App\Enums\IconeCarteira;
use App\Enums\TipoCarteira;
use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\PagamentoFatura;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    cenarioCartao();
});

/**
 * @param  array<string, mixed>  $dados
 * @return array<string, mixed>
 */
function dadosCartao(array $dados = []): array
{
    return ['nome' => 'Inter', 'tipo' => 'cartao', 'dia_vencimento' => 10, 'carteira_vinculada_id' => test()->x->id, ...$dados];
}

it('CA-160.1 creates a card wallet with its card row and the defaults', function () {
    $this->actingAs($this->user)->post(route('carteiras.store'), dadosCartao())->assertSessionHasNoErrors();

    $carteira = $this->user->carteiras()->where('nome', 'Inter')->sole();

    expect($carteira)
        ->tipo->toBe(TipoCarteira::Cartao)
        ->icone->toBe(IconeCarteira::CreditCard)
        ->cor->toBe(CorCarteira::Blue)
        ->padrao->toBeFalse()
        ->and($carteira->cartao)
        ->dia_vencimento->toBe(10)
        ->dias_fechamento->toBe(10)
        ->competencia->toBe(CompetenciaFatura::Fechamento)
        ->carteira_vinculada_id->toBe($this->x->id);
});

it('CA-160.2 creates an independent card without a linked wallet', function () {
    $this->actingAs($this->user)->post(route('carteiras.store'), dadosCartao(['carteira_vinculada_id' => null]))->assertSessionHasNoErrors();

    expect($this->user->carteiras()->where('nome', 'Inter')->sole()->cartao->carteira_vinculada_id)->toBeNull();
});

it('CA-160.3 rejects a due day or closing out of range', function (array $dados, string $campo) {
    $this->actingAs($this->user)->post(route('carteiras.store'), dadosCartao($dados))->assertSessionHasErrors($campo);

    expect($this->user->carteiras()->where('nome', 'Inter')->exists())->toBeFalse();
})->with([
    'due day 0' => [['dia_vencimento' => 0], 'dia_vencimento'],
    'due day 32' => [['dia_vencimento' => 32], 'dia_vencimento'],
    'closing 0' => [['dias_fechamento' => 0], 'dias_fechamento'],
    'closing 26' => [['dias_fechamento' => 26], 'dias_fechamento'],
]);

it('CA-160.4 rejects a linked wallet that is a card or of another user', function (Closure $vinculada) {
    $this->actingAs($this->user)
        ->post(route('carteiras.store'), dadosCartao(['carteira_vinculada_id' => $vinculada()->id]))
        ->assertSessionHasErrors('carteira_vinculada_id');
})->with([
    'card' => [fn () => test()->nubank],
    'other user' => [fn () => Carteira::factory()->create()],
]);

it('CA-160.5 ignores card fields for a common wallet', function () {
    $this->actingAs($this->user)
        ->post(route('carteiras.store'), ['nome' => 'Dinheiro', 'tipo' => 'comum', 'dia_vencimento' => 99])
        ->assertSessionHasNoErrors();

    expect($this->user->carteiras()->where('nome', 'Dinheiro')->sole())
        ->tipo->toBe(TipoCarteira::Comum)
        ->cartao->toBeNull();
});

it('CA-160.6 never makes a card the default wallet', function () {
    $this->actingAs($this->user)->post(route('carteiras.padrao', $this->nubank))->assertSessionHasErrors('carteira');

    expect($this->principal->fresh()->padrao)->toBeTrue()
        ->and($this->nubank->fresh()->padrao)->toBeFalse();
});

it('CA-161.1 never changes the wallet type on update', function () {
    $this->actingAs($this->user)->put(route('carteiras.update', $this->nubank), ['nome' => 'Nubank', 'tipo' => 'comum', 'dia_vencimento' => 10]);
    $this->actingAs($this->user)->put(route('carteiras.update', $this->x), ['nome' => 'X', 'tipo' => 'cartao']);

    expect($this->nubank->fresh()->tipo)->toBe(TipoCarteira::Cartao)
        ->and($this->x->fresh()->tipo)->toBe(TipoCarteira::Comum)
        ->and(Cartao::query()->where('carteira_id', $this->x->id)->exists())->toBeFalse();
});

it('CA-161.2 recalculates the open and future invoices when the due day changes', function () {
    $this->actingAs($this->user)
        ->put(route('carteiras.update', $this->nubank), ['nome' => 'Nubank', 'dia_vencimento' => 15, 'carteira_vinculada_id' => $this->x->id])
        ->assertSessionHasNoErrors();

    $dezembro = faturaDe($this->nubank, '2026-12');

    expect($dezembro->vencimento->toDateString())->toBe('2026-12-15')
        ->and($dezembro->fechamento->toDateString())->toBe('2026-12-05')
        ->and($this->compraDezembro->cotas()->sole()->fatura_id)->toBe($dezembro->id)
        ->and(faturaDe($this->nubank, '2027-01')->vencimento->toDateString())->toBe('2027-01-15')
        ->and(faturaDe($this->nubank, '2026-11')->only(['fechamento', 'vencimento']))
        ->toEqual(['fechamento' => CarbonImmutable::parse('2026-10-31'), 'vencimento' => CarbonImmutable::parse('2026-11-10')]);
});

it('CA-161.3 keeps the dates of an invoice edited one by one', function () {
    $this->actingAs($this->user)->put(route('faturas.datas', [$this->nubank, '2026-12']), ['fechamento' => '2026-11-28', 'vencimento' => '2026-12-10']);
    $this->actingAs($this->user)->put(route('carteiras.update', $this->nubank), ['nome' => 'Nubank', 'dia_vencimento' => 10, 'dias_fechamento' => 7, 'carteira_vinculada_id' => $this->x->id]);

    expect(faturaDe($this->nubank, '2026-12')->fechamento->toDateString())->toBe('2026-11-28')
        ->and(faturaDe($this->nubank, '2027-01')->fechamento->toDateString())->toBe('2027-01-03');
});

it('CA-161.4 CA-161.5 moves the open and future invoices to the new linked wallet and keeps payments', function () {
    $y = Carteira::factory()->for($this->user)->create(['nome' => 'Y']);

    $this->actingAs($this->user)->put(route('carteiras.update', $this->nubank), ['nome' => 'Nubank', 'dia_vencimento' => 10, 'carteira_vinculada_id' => $y->id]);

    expect($this->pagamento->fresh()->carteira_id)->toBe($this->x->id)
        ->and(faturaDe($this->nubank, '2026-11')->carteira_vinculada_id)->toBe($this->x->id)
        ->and(faturaDe($this->nubank, '2026-12')->carteira_vinculada_id)->toBe($y->id)
        ->and(faturaDe($this->nubank, '2027-01')->carteira_vinculada_id)->toBe($y->id);

    $this->actingAs($this->user)->put(route('carteiras.update', $this->nubank), ['nome' => 'Nubank', 'dia_vencimento' => 10, 'carteira_vinculada_id' => null]);

    expect($this->nubank->cartao()->sole()->carteira_vinculada_id)->toBeNull()
        ->and(faturaDe($this->nubank, '2026-12')->carteira_vinculada_id)->toBeNull()
        ->and(faturaDe($this->nubank, '2026-11')->carteira_vinculada_id)->toBe($this->x->id);
});

it('CA-162.1 puts each purchase in the invoice whose period contains its date', function () {
    expect($this->compraOutubro->cotas()->sole()->fatura_id)->toBe(faturaDe($this->nubank, '2026-11')->id)
        ->and($this->compraNovembro->cotas()->sole()->fatura_id)->toBe(faturaDe($this->nubank, '2026-12')->id)
        ->and($this->compraFimNovembro->cotas()->sole()->fatura_id)->toBe(faturaDe($this->nubank, '2026-12')->id)
        ->and($this->compraDezembro->cotas()->sole()->fatura_id)->toBe(faturaDe($this->nubank, '2027-01')->id);
});

it('CA-162.2 moves a due day missing in the month to the last day', function () {
    $cartao = cartaoDe($this->user, 'Dia 31', diaVencimento: 31);
    compraNoCartao('2027-02-10', 1000, cartao: $cartao);
    compraNoCartao('2027-03-15', 1000, cartao: $cartao);

    expect(faturaDe($cartao, '2027-02')->only(['vencimento', 'fechamento']))
        ->toEqual(['vencimento' => CarbonImmutable::parse('2027-02-28'), 'fechamento' => CarbonImmutable::parse('2027-02-18')])
        ->and(faturaDe($cartao, '2027-03')->vencimento->toDateString())->toBe('2027-03-31');
});

it('CA-162.3 CA-162.5 puts income in the invoice of its period and creates the invoice with the defaults', function () {
    $estorno = compraNoCartao('2026-11-08', 5000, $this->entrada, 'receita');
    $marco = compraNoCartao('2027-02-20', 1000);

    expect($estorno->cotas()->sole()->fatura_id)->toBe(faturaDe($this->nubank, '2026-12')->id)
        ->and(faturaDe($this->nubank, '2027-03')->only(['fechamento', 'vencimento', 'carteira_vinculada_id']))
        ->toEqual(['fechamento' => CarbonImmutable::parse('2027-02-28'), 'vencimento' => CarbonImmutable::parse('2027-03-10'), 'carteira_vinculada_id' => $this->x->id])
        ->and($marco->cotas()->sole()->fatura_id)->toBe(faturaDe($this->nubank, '2027-03')->id);
});

it('CA-162.4 moves the share to the invoice of the new date', function () {
    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $this->compraNovembro), dadosCompra(['data' => '2026-12-03', 'valor' => '200,00', 'categoria_id' => $this->lazer->id]))
        ->assertSessionHasNoErrors();

    expect($this->compraNovembro->cotas()->sole()->fatura_id)->toBe(faturaDe($this->nubank, '2027-01')->id);
});

it('CA-162.6 blocks a new purchase dated in a closed invoice', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosCompra(['data' => '2026-10-25', 'descricao' => 'Esquecida']))
        ->assertSessionHasErrors(['data' => 'Essa data cai numa fatura fechada. Use uma data a partir de 01/11/2026.']);

    expect($this->user->lancamentos()->where('descricao', 'Esquecida')->exists())->toBeFalse();
});

it('CA-163.1 moves purchases after the new closing to the next invoice and marks the dates as edited', function () {
    $this->actingAs($this->user)
        ->put(route('faturas.datas', [$this->nubank, '2026-12']), ['fechamento' => '2026-11-28', 'vencimento' => '2026-12-10'])
        ->assertSessionHasNoErrors();

    expect(faturaDe($this->nubank, '2026-12')->datas_editadas)->toBeTrue()
        ->and($this->compraFimNovembro->cotas()->sole()->fatura_id)->toBe(faturaDe($this->nubank, '2027-01')->id)
        ->and($this->compraNovembro->cotas()->sole()->fatura_id)->toBe(faturaDe($this->nubank, '2026-12')->id);
});

it('CA-163.2 keeps the closing between the previous and the next closing', function (string $fechamento) {
    $this->actingAs($this->user)
        ->put(route('faturas.datas', [$this->nubank, '2026-12']), ['fechamento' => $fechamento, 'vencimento' => '2027-01-05'])
        ->assertSessionHasErrors(['fechamento' => 'O fechamento precisa ficar entre 31/10/2026 e 31/12/2026.']);
})->with(['2026-10-31', '2026-10-20', '2026-12-31', '2027-01-02']);

it('CA-163.3 keeps the due date not before the closing', function () {
    $this->actingAs($this->user)
        ->put(route('faturas.datas', [$this->nubank, '2026-12']), ['fechamento' => '2026-11-30', 'vencimento' => '2026-11-29'])
        ->assertSessionHasErrors(['vencimento' => 'O vencimento não pode ser antes do fechamento.']);
});

it('CA-163.4 never changes the dates of a closed invoice', function () {
    $this->actingAs($this->user)
        ->put(route('faturas.datas', [$this->nubank, '2026-11']), ['fechamento' => '2026-10-30', 'vencimento' => '2026-11-10'])
        ->assertSessionHasErrors('fechamento');

    expect(faturaDe($this->nubank, '2026-11')->fechamento->toDateString())->toBe('2026-10-31');
});

it('CA-165.1 pays the invoice from its linked wallet', function () {
    $this->actingAs($this->user)
        ->post(route('faturas.pagar', [$this->nubank, '2026-12']), ['valor' => '300,00', 'data' => '2026-11-14'])
        ->assertSessionHasNoErrors();

    expect(faturaDe($this->nubank, '2026-12')->pagamentos()->sole())
        ->valor->toBe(30000)
        ->carteira_id->toBe($this->x->id);
});

it('CA-165.2 rejects a zero, negative or future payment', function (array $dados, string $campo, string $mensagem) {
    $this->actingAs($this->user)
        ->post(route('faturas.pagar', [$this->nubank, '2026-12']), ['valor' => '300,00', 'data' => '2026-11-14', ...$dados])
        ->assertSessionHasErrors([$campo => $mensagem]);
})->with([
    'zero' => [['valor' => '0'], 'valor', 'Informe um valor maior que zero.'],
    'negative' => [['valor' => '-10,00'], 'valor', 'Informe um valor válido, como 1.234,56.'],
    'future' => [['data' => '2026-11-15'], 'data', 'A data não pode ser futura.'],
]);

it('CA-165.3 accepts paying a future invoice and above what is left', function () {
    $this->actingAs($this->user)->post(route('faturas.pagar', [$this->nubank, '2027-02']), ['valor' => '10,00', 'data' => '2026-11-14'])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('faturas.pagar', [$this->nubank, '2026-12']), ['valor' => '999,00', 'data' => '2026-11-14'])->assertSessionHasNoErrors();

    expect(faturaDe($this->nubank, '2027-02')->pagamentos()->sum('valor'))->toBe(1000);
});

it('CA-165.4 pays an independent card from outside the app', function () {
    $independente = cartaoDe($this->user, 'Independente');

    $this->actingAs($this->user)->post(route('faturas.pagar', [$independente, '2026-12']), ['valor' => '10,00', 'data' => '2026-11-14'])->assertSessionHasNoErrors();

    expect(PagamentoFatura::query()->latest('id')->first()->carteira_id)->toBeNull();
});

it('CA-165.5 pays each invoice from its own linked wallet after the link changes', function () {
    $y = Carteira::factory()->for($this->user)->create(['nome' => 'Y']);
    $this->actingAs($this->user)->put(route('carteiras.update', $this->nubank), ['nome' => 'Nubank', 'dia_vencimento' => 10, 'carteira_vinculada_id' => $y->id]);

    $this->actingAs($this->user)->post(route('faturas.pagar', [$this->nubank, '2026-11']), ['valor' => '100,00', 'data' => '2026-11-14']);
    $this->actingAs($this->user)->post(route('faturas.pagar', [$this->nubank, '2026-12']), ['valor' => '100,00', 'data' => '2026-11-14']);

    expect(faturaDe($this->nubank, '2026-11')->pagamentos()->latest('id')->first()->carteira_id)->toBe($this->x->id)
        ->and(faturaDe($this->nubank, '2026-12')->pagamentos()->sole()->carteira_id)->toBe($y->id);
});

it('CA-165.6 forbids paying or editing a card of another user', function () {
    $outro = User::factory()->create();

    $this->actingAs($outro)->post(route('faturas.pagar', [$this->nubank, '2026-12']), ['valor' => '10,00', 'data' => '2026-11-14'])->assertForbidden();
    $this->actingAs($outro)->put(route('faturas.datas', [$this->nubank, '2026-12']), ['fechamento' => '2026-11-28', 'vencimento' => '2026-12-10'])->assertForbidden();
    $this->actingAs($outro)->delete(route('faturas.pagamentos.destroy', $this->pagamento))->assertForbidden();
});

it('CA-166.1 CA-166.2 deletes a payment, which has no edit route', function () {
    $this->actingAs($this->user)->delete(route('faturas.pagamentos.destroy', $this->pagamento))->assertSessionHasNoErrors();

    expect(PagamentoFatura::query()->count())->toBe(0)
        ->and(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($rota) => str_contains($rota->uri(), 'pagamentos-fatura') && in_array('PUT', $rota->methods(), true)))->toBeFalse();
});

it('RN-193 keeps the closing from today on', function () {
    $this->actingAs($this->user)
        ->put(route('faturas.datas', [$this->nubank, '2026-12']), ['fechamento' => '2026-11-13', 'vencimento' => '2026-12-10'])
        ->assertSessionHasErrors(['fechamento' => 'O fechamento não pode ser antes de hoje.']);

    expect(faturaDe($this->nubank, '2026-12')->datas_editadas)->toBeFalse();
});

it('CA-163.6 keeps the dates in the months of the competence window', function () {
    $this->actingAs($this->user)
        ->put(route('faturas.datas', [$this->nubank, '2026-12']), ['fechamento' => '2026-11-30', 'vencimento' => '2027-01-02'])
        ->assertSessionHasErrors(['vencimento' => 'O vencimento precisa ficar em novembro ou dezembro.']);

    $inter = cartaoDe($this->user, 'Inter', $this->x, diaVencimento: 15, competencia: CompetenciaFatura::Fechamento);

    $this->actingAs($this->user)
        ->put(route('faturas.datas', [$inter, '2026-12']), ['fechamento' => '2026-11-20', 'vencimento' => '2026-12-15'])
        ->assertSessionHasErrors(['fechamento' => 'O fechamento precisa ficar em dezembro ou janeiro.']);
    $this->actingAs($this->user)
        ->put(route('faturas.datas', [$inter, '2026-12']), ['fechamento' => '2026-12-05', 'vencimento' => '2027-01-05'])
        ->assertSessionHasNoErrors();

    expect(faturaDe($inter, '2026-12'))
        ->vencimento->toDateString()->toBe('2027-01-05')
        ->datas_editadas->toBeTrue();
});

it('CA-163.7 keeps the due date between the due dates of the previous and the next invoice', function () {
    $inter = cartaoDe($this->user, 'Inter', $this->x, diaVencimento: 15, competencia: CompetenciaFatura::Fechamento);

    $this->actingAs($this->user)
        ->put(route('faturas.datas', [$inter, '2026-12']), ['fechamento' => '2026-12-05', 'vencimento' => '2027-01-20'])
        ->assertSessionHasErrors(['vencimento' => 'O vencimento precisa ficar entre 15/11/2026 e 15/01/2027.']);
});

it('RN-220 refuses a card change that would close the open invoice before today or out of order', function () {
    $this->actingAs($this->user)
        ->put(route('carteiras.update', $this->nubank), ['nome' => 'Nubank', 'dia_vencimento' => 1, 'dias_fechamento' => 25, 'carteira_vinculada_id' => $this->x->id])
        ->assertSessionHasErrors(['dia_vencimento' => 'Com esse vencimento e fechamento, a fatura aberta fecharia antes de hoje ou fora de ordem. Escolha outros dias.']);

    expect($this->nubank->cartao()->sole()->dia_vencimento)->toBe(10)
        ->and(faturaDe($this->nubank, '2026-12')->fechamento->toDateString())->toBe('2026-11-30');
});

it('RN-232 puts an installment the system generates in a closed invoice into the open invoice', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-05 10:00'));
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->nubank, $this->mercado, ['data' => '2026-08-10', 'valor' => '100,00', 'modo_valor' => 'parcela', 'total_parcelas' => 2]))
        ->assertSessionHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $serie = $this->user->parcelamentos()->sole();

    $this->actingAs($this->user)->patch(route('parcelamentos.total', $serie), ['total_parcelas' => 3])->assertSessionHasNoErrors();

    expect($serie->lancamentos()->where('numero_parcela', 3)->sole()->cotas()->sole()->fatura_id)->toBe(faturaDe($this->nubank, '2026-12')->id);
});
