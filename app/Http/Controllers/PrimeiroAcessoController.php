<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResponderModoSimplesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class PrimeiroAcessoController extends Controller
{
    /**
     * Save the answer to the simple mode question and end the first access (RN-155, RN-156).
     * After the first access the answer no longer changes anything; Aparência does (RN-157). The
     * redirect drops "?novo=1", so the new entry form does not open again after the answer.
     */
    public function __invoke(ResponderModoSimplesRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->primeiro_acesso_em === null) {
            $user->forceFill([
                'modo_simples' => $request->boolean('modo_simples'),
                'primeiro_acesso_em' => now(),
            ])->save();
        }

        return to_route('home');
    }
}
