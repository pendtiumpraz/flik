<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\CaptchaPassed;
use App\Rules\NotBreached;
use App\Rules\StrongPassword;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\Rule;

class RegisterController extends Controller
{
    public function create(): View|Factory
    {
        return view('auth.register');
    }

    public function store(): Redirector|RedirectResponse
    {
        // Build a transient User shell from the submitted identity fields so
        // StrongPassword can reject passwords that contain the user's own
        // name / username / email-local-part. Never persisted — the real
        // create() below uses validated data. Set attributes directly to
        // avoid the mass-assignment guard (username isn't in $fillable).
        $context = new User;
        $context->name = (string) request()->input('name', '');
        $context->email = (string) request()->input('email', '');
        $context->username = (string) request()->input('username', '');

        // Cloudflare Turnstile CAPTCHA — the field is `cf-turnstile-response`
        // (Cloudflare widget convention). CaptchaPassed is a graceful no-op
        // when TURNSTILE_SITE_KEY/SECRET_KEY are unset, so this `required` is
        // wrapped in a `sometimes`-style guard via the rule itself. We still
        // include it in the rule list so production registrations are gated.
        request()->validate([
            'cf-turnstile-response' => ['nullable', 'string', new CaptchaPassed],
        ]);

        $attributes = request()->validate([
            'name' => ['required', 'max:255'],
            'username' => ['required', 'min:3', 'max:255', Rule::unique('users', 'username')],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'max:255', 'confirmed', new StrongPassword($context), new NotBreached],
        ]);

        $user = User::create($attributes);

        // Fire Registered → EventServiceProvider's SendEmailVerificationNotification
        // listener calls $user->sendEmailVerificationNotification() (overridden
        // on the User model to send the FLiK-branded mailable). Don't double-call
        // here — that would send the verification email twice.
        event(new Registered($user));

        auth()->login($user);

        return redirect()->route('verification.notice')
            ->with('success', 'Akun dibuat. Cek email kamu untuk verifikasi.');
    }
}
