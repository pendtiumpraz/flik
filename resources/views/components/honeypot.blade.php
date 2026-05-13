{{--
    Anti-bot honeypot fields. Pair with the `honeypot` middleware on the
    matching POST route (see App\Http\Middleware\Honeypot + the route
    declarations in routes/web.php for /login, /register, /forgot-password,
    /reset-password, /newsletter).

    Two pieces:

    1. Hidden trap input — name comes from `config('security.honeypot.field')`
       (default `website_url`). Real users never see or focus the field:
         - Off-screen position + 0×0 size + opacity 0
         - aria-hidden=true so screen readers ignore it
         - tabindex=-1 so keyboard users can't tab in
         - autocomplete="off" so password managers don't try to fill it
         - inputmode/name chosen so Chrome's address-book autofill won't
           target it (a generic `website_url` is not a recognised autofill
           field name in any major browser)
       A non-empty submission is treated as bot traffic by the middleware.

    2. `_form_start_time` — unix timestamp of the render. The middleware
       rejects POSTs received within `config('security.honeypot.min_seconds')`
       (default 2 s). Humans can't fill the shortest auth form (login) in
       under 2 seconds; bots typically POST instantly after a GET.

    The render is gated on config('security.honeypot.enabled') so flipping
    HONEYPOT_ENABLED=false in env hides the markup AND short-circuits the
    middleware in one step (no mid-deploy mismatch where the field exists
    but the check is off, or vice versa).
--}}
@if(config('security.honeypot.enabled', true))
    @php
        $honeypotField = (string) config('security.honeypot.field', 'website_url');
        if ($honeypotField === '') {
            $honeypotField = 'website_url';
        }
    @endphp

    {{-- Visually-hidden wrapper. Inline styles so it works without the
         Tailwind sr-only class (the auth views use Tailwind but we don't
         want to depend on a specific utility class being compiled). --}}
    <div aria-hidden="true"
         style="position:absolute !important;left:-9999px !important;top:auto !important;width:1px !important;height:1px !important;overflow:hidden !important;opacity:0 !important;pointer-events:none !important;">
        <label for="{{ $honeypotField }}">Leave this field empty</label>
        <input type="text"
               name="{{ $honeypotField }}"
               id="{{ $honeypotField }}"
               value=""
               autocomplete="off"
               tabindex="-1">
    </div>

    {{-- Form-fill timestamp. Unix seconds at render time; the middleware
         compares against time() at POST. Hidden the same way as the trap
         so a bot that scrapes for *all* hidden inputs still sees it. --}}
    <input type="hidden" name="_form_start_time" value="{{ now()->timestamp }}">
@endif
