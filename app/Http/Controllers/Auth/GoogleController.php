<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class GoogleController extends Controller
{
    /**
     * Send the visitor to Google's consent screen (RF-005).
     */
    public function redirect(): SymfonyRedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Sign in an existing active user by the Google account email, never creating accounts
     * (RN-106, RN-136).
     */
    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return $this->recusar('Não foi possível entrar com o Google.');
        }

        $dadosGoogle = $googleUser instanceof AbstractUser ? $googleUser->getRaw() : [];

        if (($dadosGoogle['email_verified'] ?? false) !== true) {
            return $this->recusar('O e-mail desta conta Google não está verificado.');
        }

        $user = User::query()->where('email', Str::lower((string) $googleUser->getEmail()))->first();

        if ($user === null) {
            return $this->recusar('Não existe conta cadastrada para este e-mail do Google.');
        }

        if ($user->desativado_em !== null) {
            return $this->recusar('Conta desativada.');
        }

        $vinculadaAOutroUsuario = User::query()
            ->where('google_id', $googleUser->getId())
            ->whereKeyNot($user->getKey())
            ->exists();

        if ($vinculadaAOutroUsuario) {
            return $this->recusar('Esta conta Google já está vinculada a outro usuário.');
        }

        $user->forceFill([
            'google_id' => $googleUser->getId(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::guard('web')->login($user, remember: true);
        request()->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    private function recusar(string $mensagem): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $mensagem]);
    }
}
