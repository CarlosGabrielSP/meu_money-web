<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TutorialVistoController extends Controller
{
    /**
     * The tour was finished or skipped (RN-165). Only the first time is saved, so a tour opened
     * again with "Ver tutorial" does not change the date (RN-167, CA-121.2). The redirect keeps the
     * wallet, month and filters of the screen and drops "?tutorial=1" and "?novo=1", so neither the
     * tour nor the new entry form opens again (DEC-069).
     */
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->tutorial_visto_em === null) {
            $user->forceFill(['tutorial_visto_em' => now()])->save();
        }

        parse_str((string) parse_url(url()->previous(), PHP_URL_QUERY), $query);
        unset($query['tutorial'], $query['novo']);

        return to_route('home', $query);
    }
}
