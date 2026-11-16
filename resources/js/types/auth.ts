import type { Paleta } from './ui';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    is_admin: boolean;
    paleta: Paleta;
    data_nascimento: string | null;
    modo_simples: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    temSenha: boolean;
};
