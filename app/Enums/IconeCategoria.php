<?php

namespace App\Enums;

enum IconeCategoria: string
{
    case Tag = 'tag';
    case Banknote = 'banknote';
    case Briefcase = 'briefcase';
    case Laptop = 'laptop';
    case TrendingUp = 'trending-up';
    case PiggyBank = 'piggy-bank';
    case HandCoins = 'hand-coins';
    case House = 'house';
    case Zap = 'zap';
    case Droplet = 'droplet';
    case Wifi = 'wifi';
    case Smartphone = 'smartphone';
    case Utensils = 'utensils';
    case Coffee = 'coffee';
    case ShoppingCart = 'shopping-cart';
    case Car = 'car';
    case Bus = 'bus';
    case Fuel = 'fuel';
    case Plane = 'plane';
    case HeartPulse = 'heart-pulse';
    case Dumbbell = 'dumbbell';
    case GraduationCap = 'graduation-cap';
    case Gamepad2 = 'gamepad-2';
    case Gift = 'gift';
    case Baby = 'baby';
    case PawPrint = 'paw-print';
    case Shirt = 'shirt';
    case Repeat = 'repeat';
    case Receipt = 'receipt';
    case Landmark = 'landmark';
    case CreditCard = 'credit-card';
    case Wrench = 'wrench';

    /**
     * Icon of each system category, by name (DEC-073).
     *
     * @var array<string, self>
     */
    public const array GLOBAIS = [
        'Salário' => self::Banknote,
        'Freelance' => self::Laptop,
        'Investimentos' => self::TrendingUp,
        'Reembolso' => self::HandCoins,
        'Moradia' => self::House,
        'Alimentação' => self::Utensils,
        'Mercado' => self::ShoppingCart,
        'Transporte' => self::Car,
        'Saúde' => self::HeartPulse,
        'Educação' => self::GraduationCap,
        'Lazer' => self::Gamepad2,
        'Assinaturas' => self::Repeat,
        'Contas' => self::Receipt,
        'Vestuário' => self::Shirt,
        'Impostos' => self::Landmark,
        'Cartão de crédito' => self::CreditCard,
        'Outros' => self::Tag,
    ];

    /**
     * Icon of a system category; unknown names get the default (DEC-073).
     */
    public static function daGlobal(string $nome): self
    {
        return self::GLOBAIS[$nome] ?? self::Tag;
    }
}
