<?php

use App\Enums\IconeCategoria;
use App\Enums\TipoCategoria;
use App\Models\Categoria;
use Database\Seeders\CategoriasGlobaisSeeder;

it('CA-034.1 creates the 17 system categories with their types', function () {
    $this->seed(CategoriasGlobaisSeeder::class);

    $tiposPorNome = Categoria::query()->globais()->pluck('tipo', 'nome');

    expect($tiposPorNome)->toHaveCount(17)
        ->and($tiposPorNome->filter(fn (TipoCategoria $tipo) => $tipo === TipoCategoria::Receita)->keys()->all())
        ->toEqualCanonicalizing(['Salário', 'Freelance', 'Investimentos', 'Reembolso'])
        ->and($tiposPorNome->filter(fn (TipoCategoria $tipo) => $tipo === TipoCategoria::Despesa)->keys()->all())
        ->toEqualCanonicalizing([
            'Moradia', 'Alimentação', 'Mercado', 'Transporte', 'Saúde', 'Educação',
            'Lazer', 'Assinaturas', 'Contas', 'Vestuário', 'Impostos', 'Cartão de crédito',
        ])
        ->and($tiposPorNome['Outros'])->toBe(TipoCategoria::Ambos);
});

it('CA-034.2 does not duplicate categories when run again', function () {
    $this->seed(CategoriasGlobaisSeeder::class);
    $this->seed(CategoriasGlobaisSeeder::class);

    expect(Categoria::query()->globais()->count())->toBe(17);
});

it('CA-135.4 gives each system category the icon of the map', function () {
    $this->seed(CategoriasGlobaisSeeder::class);

    expect(Categoria::query()->globais()->get()->mapWithKeys(fn (Categoria $categoria) => [$categoria->nome => $categoria->icone])->all())
        ->toEqualCanonicalizing(IconeCategoria::GLOBAIS)
        ->and(Categoria::query()->globais()->where('nome', 'Mercado')->sole()->icone)->toBe(IconeCategoria::ShoppingCart);
});
