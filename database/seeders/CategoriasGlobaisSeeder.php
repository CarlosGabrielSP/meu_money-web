<?php

namespace Database\Seeders;

use App\Enums\IconeCategoria;
use App\Enums\TipoCategoria;
use App\Models\Categoria;
use Illuminate\Database\Seeder;

class CategoriasGlobaisSeeder extends Seeder
{
    /**
     * System categories available to every user (RN-034, RN-083).
     *
     * @var array<string, list<string>>
     */
    private const array CATEGORIAS = [
        'receita' => ['Salário', 'Freelance', 'Investimentos', 'Reembolso'],
        'despesa' => [
            'Moradia', 'Alimentação', 'Mercado', 'Transporte', 'Saúde', 'Educação',
            'Lazer', 'Assinaturas', 'Contas', 'Vestuário', 'Impostos', 'Cartão de crédito',
        ],
        'ambos' => ['Outros'],
    ];

    /**
     * Create the system categories with their icons (DEC-073), skipping the ones that already exist.
     */
    public function run(): void
    {
        foreach (self::CATEGORIAS as $tipo => $nomes) {
            foreach ($nomes as $nome) {
                Categoria::query()->firstOrCreate(
                    ['user_id' => null, 'nome' => $nome],
                    ['tipo' => TipoCategoria::from($tipo), 'icone' => IconeCategoria::daGlobal($nome)],
                );
            }
        }
    }
}
