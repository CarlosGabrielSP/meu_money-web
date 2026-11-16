<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { CircleHelp, LogOut, ShieldCheck } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useModoSimples } from '@/composables/useModoSimples';
import { toUrl } from '@/lib/utils';
import { home, logout } from '@/routes';
import { index as usuariosIndex } from '@/routes/admin/usuarios';
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
        title: 'Segurança',
        href: editSecurity(),
    },
    {
        title: 'Aparência',
        href: editAppearance(),
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
const page = usePage();
const modoSimples = useModoSimples();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Configurações"
            description="Gerencie seu perfil e sua conta"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
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
                    <!-- The simple mode bar has no "Mais", so its links live here (RN-163, DEC-068). -->
                    <template v-if="modoSimples">
                        <Button
                            v-if="page.props.auth.user.is_admin"
                            variant="ghost"
                            class="w-full justify-start"
                            as-child
                        >
                            <Link :href="usuariosIndex()">
                                <ShieldCheck /> Administração
                            </Link>
                        </Button>
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
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
