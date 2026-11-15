<?php

use App\Enums\TipoCategoria;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->user = User::factory()->create();
    $this->carteira = Carteira::factory()->for($this->user)->padrao()->create();
    $this->categoria = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Ambos]);

    $this->actingAs($this->user)->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, [
        'tipo' => 'receita',
        'valor' => '3.000,00',
        'data' => '2026-11-05',
        'repeticao' => 'recorrente',
        'total_parcelas' => null,
        'modo_valor' => null,
    ]));
    $this->serie = $this->user->parcelamentos()->sole();
});

it('CA-052.1 generates the next installment and moves gerado_ate', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-06 03:00'));

    $this->artisan('series:estender-recorrentes')->assertSuccessful();

    $ultima = $this->serie->lancamentos()->orderByDesc('numero_parcela')->first();

    expect($this->serie->fresh()->gerado_ate->toDateString())->toBe('2027-12-05')
        ->and($ultima->data->toDateString())->toBe('2027-12-05')
        ->and($ultima->numero_parcela)->toBe(14)
        ->and($ultima->cotas()->sole())->executado->toBeFalse()->carteira_id->toBe($this->carteira->id);
});

it('CA-052.2 never duplicates installments when it runs twice', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-06 03:00'));

    $this->artisan('series:estender-recorrentes');
    $this->artisan('series:estender-recorrentes');

    expect($this->serie->lancamentos()->count())->toBe(14);
});

it('CA-052.3 never recreates a deleted installment', function () {
    $this->serie->lancamentos()->where('numero_parcela', 5)->delete();
    $this->travelTo(CarbonImmutable::parse('2026-12-06 03:00'));

    $this->artisan('series:estender-recorrentes');

    expect($this->serie->lancamentos()->where('numero_parcela', 5)->exists())->toBeFalse()
        ->and($this->serie->lancamentos()->count())->toBe(13);
});

it('CA-052.4 generates nothing for an ended series', function () {
    $this->serie->update(['encerrado_em' => '2027-06-30']);
    $this->travelTo(CarbonImmutable::parse('2026-12-06 03:00'));

    $this->artisan('series:estender-recorrentes');

    expect($this->serie->lancamentos()->count())->toBe(13);
});

it('CA-052.4 keeps generating a series ended in the future up to its end date', function () {
    $this->serie->update(['encerrado_em' => '2028-02-20']);
    $this->travelTo(CarbonImmutable::parse('2027-06-06 03:00'));

    $this->artisan('series:estender-recorrentes');

    expect($this->serie->fresh()->gerado_ate->toDateString())->toBe('2028-02-05')
        ->and($this->serie->lancamentos()->max('numero_parcela'))->toBe(16);
});

it('CA-052.6 is scheduled daily', function () {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($evento) => str_contains((string) $evento->command, 'series:estender-recorrentes'));

    expect($evento)->not->toBeNull()
        ->and($evento->expression)->toBe('0 0 * * *');
});
