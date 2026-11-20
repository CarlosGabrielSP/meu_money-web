<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
    }

    /**
     * Limit applied to the whole web group (RNF-028, RNF-029, DEC-094): 300 requests a minute per
     * signed-in user (per IP for a visitor), plus 10 a minute per IP on the visitor routes that send
     * e-mail or start the Google login. The login itself keeps its own limit (RNF-024).
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('geral', function (Request $request): array {
            $limites = [Limit::perMinute(300)->by($request->user() === null ? "ip|{$request->ip()}" : "usuario|{$request->user()->id}")];

            if ($request->routeIs('password.email', 'password.update', 'auth.google.redirect', 'auth.google.callback')) {
                $limites[] = Limit::perMinute(10)->by("visitante|{$request->ip()}");
            }

            return $limites;
        });
    }

    /**
     * Only administrators manage accounts (RN-107, CA-086.3).
     */
    protected function configureAuthorization(): void
    {
        Gate::define('administrar', fn (User $user): bool => $user->is_admin);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
