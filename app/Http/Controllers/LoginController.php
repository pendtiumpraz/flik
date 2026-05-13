<?php

namespace App\Http\Controllers;

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

class LoginController extends Controller
{
    /**
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirectToProvider()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * @return \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse
     */
    public function handleProviderCallback()
    {
        $googleUser = Socialite::driver('google')->user();

        // SECURITY: `provider_id` and `email_verified_at` are intentionally NOT
        // in User::$fillable (mass-assignment audit). Look the user up first
        // and write provider-set fields through forceFill so they actually land.
        $user = User::where('provider_id', $googleUser->getId())->first();

        if (! $user) {
            $user = new User();
            $user->name = $googleUser->getName();
            $user->email = $googleUser->getEmail();
            $user->forceFill([
                'provider_id' => $googleUser->getId(),
                // OAuth identity providers vouch for the email address — mark it verified.
                'email_verified_at' => now(),
            ])->save();
        }

        // Log the user in
        auth()->login($user);

        // Redirect to movies
        return redirect('/movies')->with('success', 'Your account has been created');
    }
}
