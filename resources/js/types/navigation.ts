import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    disabled?: boolean;
    contador?: number;
    /** Runs instead of following the link, e.g. "Adicionar" (RN-163). */
    acao?: () => void;
    /** `data-tutorial` value the tour highlights (RN-164). */
    tutorial?: string;
};
