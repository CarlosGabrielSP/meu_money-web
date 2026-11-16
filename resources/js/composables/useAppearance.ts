import type { ComputedRef, Ref } from 'vue';
import { computed, onMounted, ref } from 'vue';
import type { Appearance, Paleta, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

export type UseAppearanceReturn = {
    appearance: Ref<Appearance>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

export function updateTheme(value: Appearance): void {
    if (typeof window === 'undefined') {
        return;
    }

    const isDark =
        value === 'system'
            ? window.matchMedia('(prefers-color-scheme: dark)').matches
            : value === 'dark';

    document.documentElement.classList.toggle('dark', isDark);

    // Browser bar color = main background of the palette in the active mode (DEC-062)...
    const themeColor = getComputedStyle(document.documentElement)
        .getPropertyValue('--theme-color')
        .trim();

    if (themeColor) {
        document
            .querySelector('meta[name="theme-color"]')
            ?.setAttribute('content', themeColor);
    }
}

/**
 * Simple mode of the account on the html element, which the CSS reads (RN-157, RNF-007).
 */
export function updateModoSimples(modoSimples: boolean): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.toggleAttribute('data-modo-simples', modoSimples);
}

/**
 * Applies the account palette (RN-152) without reloading the page.
 */
export function updatePaleta(paleta: Paleta): void {
    if (typeof document === 'undefined') {
        return;
    }

    if (document.documentElement.dataset.paleta === paleta) {
        return;
    }

    document.documentElement.dataset.paleta = paleta;
    updateTheme(getStoredAppearance() || 'system');
}

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const getStoredAppearance = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return localStorage.getItem('appearance') as Appearance | null;
};

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const handleSystemThemeChange = () => {
    const currentAppearance = getStoredAppearance();

    updateTheme(currentAppearance || 'system');
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    // Initialize theme from saved preference or default to system...
    const savedAppearance = getStoredAppearance();
    updateTheme(savedAppearance || 'system');

    // Set up system theme change listener...
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

const appearance = ref<Appearance>('system');

export function useAppearance(): UseAppearanceReturn {
    onMounted(() => {
        const savedAppearance = localStorage.getItem(
            'appearance',
        ) as Appearance | null;

        if (savedAppearance) {
            appearance.value = savedAppearance;
        }
    });

    const resolvedAppearance = computed<ResolvedAppearance>(() => {
        if (appearance.value === 'system') {
            return prefersDark() ? 'dark' : 'light';
        }

        return appearance.value;
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;

        // Store in localStorage for client-side persistence...
        localStorage.setItem('appearance', value);

        // Store in cookie for SSR...
        setCookie('appearance', value);

        updateTheme(value);
    }

    return {
        appearance,
        resolvedAppearance,
        updateAppearance,
    };
}
