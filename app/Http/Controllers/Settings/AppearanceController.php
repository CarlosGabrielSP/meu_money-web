<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Paleta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AppearanceUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    /**
     * Show the appearance settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Appearance', [
            'paletas' => array_map(
                fn (Paleta $paleta): array => ['valor' => $paleta->value, 'nome' => $paleta->rotulo()],
                Paleta::cases(),
            ),
        ]);
    }

    /**
     * Save the palette or the simple mode in the account; light, dark and system stay per device
     * (RN-152, RN-157).
     */
    public function update(AppearanceUpdateRequest $request): RedirectResponse
    {
        $request->user()->update(array_filter([
            'paleta' => $request->enum('paleta', Paleta::class),
            'modo_simples' => $request->has('modo_simples') ? $request->boolean('modo_simples') : null,
        ], fn (mixed $valor): bool => $valor !== null));

        return to_route('appearance.edit');
    }
}
