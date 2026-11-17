<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { CircleHelp, LogOut } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useModoSimples } from '@/composables/useModoSimples';
import { toUrl } from '@/lib/utils';
import { home, logout } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Perfil',
        href: editProfile(),
    },
    {
        title: 'Alterar senha',
        href: editSecurity(),
    },
    {
        title: 'Aparência',
        href: editAppearance(),
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
const modoSimples = useModoSimples();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Configurações"
            description="Gerencie seu perfil e sua conta"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <!-- Base style of Fase 12 (DEC-070): menu and content in cards, like the other pages. -->
            <aside class="w-full max-w-xl lg:w-52">
                <nav
                    class="flex flex-col space-y-1 space-x-0 rounded-2xl border bg-card p-1.5"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            {
                                'bg-muted font-medium': isCurrentOrParentUrl(
                                    item.href,
                                ),
                            },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                    <!-- "Ver tutorial" lives only here, in both modes (RN-167, DEC-069). -->
                    <Button
                        variant="ghost"
                        class="w-full justify-start"
                        as-child
                    >
                        <Link :href="home({ query: { tutorial: 1 } })">
                            <CircleHelp /> Ver tutorial
                        </Link>
                    </Button>
                    <!-- "Sair" stays here in the simple mode (DEC-068); "Administração" is in the menu (DEC-076). -->
                    <template v-if="modoSimples">
                        <Button
                            variant="ghost"
                            class="w-full justify-start"
                            as-child
                        >
                            <Link
                                :href="logout()"
                                as="button"
                                data-test="logout-simples"
                                @click="router.flushAll()"
                            >
                                <LogOut /> Sair
                            </Link>
                        </Button>
                    </template>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section
                    class="max-w-xl space-y-12 rounded-2xl border bg-card p-4 md:p-6"
                >
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
