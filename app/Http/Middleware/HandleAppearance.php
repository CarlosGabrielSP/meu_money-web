<?php

namespace App\Http\Middleware;

use App\Enums\Paleta;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Share the device appearance (cookie) and the account palette with the root view (RNF-002, RN-152).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('appearance', $request->cookie('appearance') ?? 'system');
        View::share('paleta', $request->user()->paleta ?? Paleta::Neutro);

        return $next($request);
    }
}
