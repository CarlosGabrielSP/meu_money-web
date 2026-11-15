<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\SenhaDefinidaResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\PasswordResetResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PasswordResetResponse::class, SenhaDefinidaResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    /**
     * Authenticate by email and password, refusing deactivated accounts (RN-108, CA-004.2).
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', Str::lower((string) $request->input(Fortify::username())))->first();

            if ($user === null || $user->password === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            if ($user->desativado_em !== null) {
                throw ValidationException::withMessages([Fortify::username() => 'Conta desativada.']);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canLoginWithGoogle' => filled(config('services.google.client_id')),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(function (Request $request) {
            $user = User::query()->where('email', Str::lower((string) $request->query('email')))->first();
            $token = (string) $request->route('token');
            $tokenValido = $user !== null && PasswordBroker::broker()->tokenExists($user, $token);

            // Account details are only revealed with a valid token, so the page cannot enumerate users.
            return Inertia::render('auth/ResetPassword', [
                'email' => $request->email,
                'token' => $token,
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
                'tokenValido' => $tokenValido,
                'temSenha' => $tokenValido && $user->password !== null,
            ]);
        });

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

    }
}
