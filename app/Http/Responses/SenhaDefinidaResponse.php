<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\PasswordResetResponse;
use Laravel\Fortify\Fortify;

class SenhaDefinidaResponse implements PasswordResetResponse
{
    public function __construct(protected string $status) {}

    /**
     * Send the user, already authenticated by the reset, to the application (CA-003.1).
     *
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['message' => trans($this->status)], 200);
        }

        if (! Auth::guard('web')->check()) {
            return redirect()->route('login')->withErrors(['email' => 'Conta desativada.']);
        }

        return redirect()->intended(Fortify::redirects('password-reset', config('fortify.home')))
            ->with('status', trans($this->status));
    }
}
