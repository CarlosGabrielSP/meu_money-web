export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';
export type Paleta =
    | 'neutro'
    | 'esmeralda'
    | 'ardosia'
    | 'obsidiana'
    | 'areia'
    | 'grafite';
export type OpcaoPaleta = { valor: Paleta; nome: string };

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};
