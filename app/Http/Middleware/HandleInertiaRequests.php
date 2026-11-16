<?php

namespace App\Http\Middleware;

use App\Models\Contato;
use App\Models\Cota;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'temSenha' => $request->user()?->password !== null,
            ],
            'modoSimples' => (bool) $request->user()?->modo_simples,
            'contadores' => fn (): array => [
                'convites' => $request->user() === null ? 0 : Contato::query()->pendentesRecebidos($request->user())->count(),
                'recebidas' => $request->user() === null ? 0 : Cota::query()->recebidasPor($request->user())->whereNull('vista_em')->count(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
