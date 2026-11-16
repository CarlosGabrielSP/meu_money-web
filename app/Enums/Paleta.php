<?php

namespace App\Enums;

/**
 * Color palette chosen in the account (RN-151, RN-152, DEC-062).
 */
enum Paleta: string
{
    case Neutro = 'neutro';
    case Esmeralda = 'esmeralda';
    case Ardosia = 'ardosia';
    case Obsidiana = 'obsidiana';
    case Areia = 'areia';
    case Grafite = 'grafite';

    /**
     * Name shown in Aparência (DEC-062).
     */
    public function rotulo(): string
    {
        return match ($this) {
            self::Neutro => 'Neutro',
            self::Esmeralda => 'Esmeralda',
            self::Ardosia => 'Ardósia e Cobalto',
            self::Obsidiana => 'Obsidiana e Índigo',
            self::Areia => 'Areia e Terracota',
            self::Grafite => 'Grafite e Violeta',
        };
    }

    /**
     * Main background of the palette, used as the browser bar color (DEC-059, DEC-062).
     * Must match `--theme-color` in resources/css/app.css.
     *
     * @return array{claro: string, escuro: string}
     */
    public function fundo(): array
    {
        return match ($this) {
            self::Neutro => ['claro' => '#ffffff', 'escuro' => '#0a0a0a'],
            self::Esmeralda => ['claro' => '#f6f9f8', 'escuro' => '#09110e'],
            self::Ardosia => ['claro' => '#f8fafc', 'escuro' => '#090d16'],
            self::Obsidiana => ['claro' => '#f5f7ff', 'escuro' => '#0b0f19'],
            self::Areia => ['claro' => '#faf9f6', 'escuro' => '#141210'],
            self::Grafite => ['claro' => '#f4f5f7', 'escuro' => '#0c0c0e'],
        };
    }
}
