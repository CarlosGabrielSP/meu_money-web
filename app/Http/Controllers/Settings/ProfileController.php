<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Usuarios\ExcluirConta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Profile');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->safe()->only(['name', 'data_nascimento']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, ExcluirConta $excluirConta): RedirectResponse
    {
        $user = $request->user();

        $excluirConta->handle($user, $request->campoConfirmacao());

        // logout() would save the deleted user again (remember token), so the session is dropped directly.
        Auth::guard('web')->forgetUser();
        Cookie::queue(Cookie::forget(Auth::guard('web')->getRecallerName()));

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
