<x-admin.layout title="New Push Broadcast">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:700;color:#fff;letter-spacing:0.5px">
                Compose Push Broadcast
            </h2>
            <p style="color:#888;font-size:13px;margin-top:4px">
                Reach FLiK users in their browser — even when the tab is closed.
            </p>
        </div>
        <a href="{{ route('admin.push.index') }}" class="btn btn-ghost">← Back</a>
    </div>

    @unless ($pushEnabled)
        <div style="background:rgba(239,68,68,0.1);border:1px solid #ef4444;border-radius:10px;padding:14px 18px;margin-bottom:20px;color:#fca5a5;font-size:13px">
            <strong style="color:#fff">VAPID is not configured.</strong>
            Form submissions will be rejected until you generate keys via
            <code style="background:#000;padding:2px 6px;border-radius:4px;color:#C5A55A">php artisan flik:push:generate-vapid-keys</code>.
        </div>
    @endunless

    @if ($errors->any())
        <div style="background:rgba(239,68,68,0.1);border:1px solid #ef4444;border-radius:10px;padding:12px 16px;margin-bottom:20px">
            <ul style="margin:0;padding-left:20px;color:#fca5a5;font-size:13px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.push.store') }}"
          style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;max-width:760px">
        @csrf

        <div class="form-group">
            <label for="title">Title <span style="color:#ef4444">*</span></label>
            <input type="text" name="title" id="title" required maxlength="200"
                   class="form-input" value="{{ old('title') }}"
                   placeholder="e.g. New on FLiK: Pengabdi Setan 3">
            <p style="font-size:11.5px;color:#666;margin-top:4px">Shown as the notification headline. Keep under 50 chars for mobile.</p>
        </div>

        <div class="form-group">
            <label for="body">Body <span style="color:#ef4444">*</span></label>
            <textarea name="body" id="body" required maxlength="2000" rows="3"
                      class="form-input" placeholder="Tonton sekarang di FLiK — gratis untuk pelanggan Premium.">{{ old('body') }}</textarea>
            <p style="font-size:11.5px;color:#666;margin-top:4px">2–3 sentences max. Browsers truncate aggressively on mobile.</p>
        </div>

        <div class="form-group">
            <label for="audience">Audience <span style="color:#ef4444">*</span></label>
            <select name="audience" id="audience" required class="form-input">
                @foreach ($audienceOptions as $value => $label)
                    <option value="{{ $value }}" @selected(old('audience') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <p style="font-size:11.5px;color:#666;margin-top:4px">
                Or enter a custom token (e.g. <code style="background:#0f0f0f;padding:1px 6px;border-radius:3px;color:#C5A55A">user:42</code>):
            </p>
            <input type="text" name="audience_custom" form="_unused"
                   placeholder="user:123  or  role:editor  or  segment:vip"
                   oninput="document.getElementById('audience').value = this.value || document.getElementById('audience').options[0].value"
                   class="form-input" style="margin-top:6px">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            <div class="form-group">
                <label for="action_url">Action URL <span style="color:#888;font-weight:400">(optional)</span></label>
                <input type="url" name="action_url" id="action_url" maxlength="500"
                       class="form-input" value="{{ old('action_url') }}"
                       placeholder="{{ url('/movie/pengabdi-setan-3') }}">
                <p style="font-size:11.5px;color:#666;margin-top:4px">Opens this URL when the user clicks the notification.</p>
            </div>

            <div class="form-group">
                <label for="tag">Tag <span style="color:#888;font-weight:400">(optional)</span></label>
                <input type="text" name="tag" id="tag" maxlength="40"
                       class="form-input" value="{{ old('tag') }}"
                       placeholder="newrelease">
                <p style="font-size:11.5px;color:#666;margin-top:4px">Same tag = browser collapses repeats into one notification.</p>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            <div class="form-group">
                <label for="icon_url">Icon URL <span style="color:#888;font-weight:400">(optional)</span></label>
                <input type="url" name="icon_url" id="icon_url" maxlength="500"
                       class="form-input" value="{{ old('icon_url') }}"
                       placeholder="{{ asset('img/flik-logo.png') }}">
            </div>

            <div class="form-group">
                <label for="badge_url">Badge URL <span style="color:#888;font-weight:400">(optional)</span></label>
                <input type="url" name="badge_url" id="badge_url" maxlength="500"
                       class="form-input" value="{{ old('badge_url') }}"
                       placeholder="{{ asset('img/flik-logo.png') }}">
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px">
            <a href="{{ route('admin.push.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-gold" @disabled(! $pushEnabled)>
                Queue broadcast
            </button>
        </div>
    </form>

</x-admin.layout>
