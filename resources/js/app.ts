import { createInertiaApp, router } from '@inertiajs/vue3';
import { initializeTheme, updatePaleta } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import type { Page } from '@inertiajs/core';
import type { Auth } from '@/types';

const appName = import.meta.env.VITE_APP_NAME || 'Meu Money';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// Keeps the account palette in sync with server responses (choice in Aparência, login, logout); visitors use Neutro (RN-152).
// "navigate" skips visits that end on the same URL (Inertia replaces the history entry), so "success" covers those.
// Back/forward restores old props from history, so it never changes the palette...
const sincronizarPaleta = (page: Page): void => {
    const auth = page.props.auth as Auth | undefined;

    updatePaleta(auth?.user?.paleta ?? 'neutro');
};

router.on('navigate', (event) => {
    if (event.detail.type !== 'history') {
        sincronizarPaleta(event.detail.page);
    }
});
router.on('success', (event) => sincronizarPaleta(event.detail.page));

// This will listen for flash toast data from the server...
initializeFlashToast();

// Minimal service worker so the app can be installed (RNF-003)...
if (typeof window !== 'undefined' && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Installation is optional: the app works without the service worker.
        });
    });
}
