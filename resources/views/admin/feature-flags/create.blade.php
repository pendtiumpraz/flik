{{--
    Feature Flag — create form.

    Identity fields only (key/name/description). The newly-created flag
    is always saved as off + 'off' strategy by the controller; configure
    strategy + ramp on the edit screen after creation. This is deliberate
    so an accidentally-saved row never goes live silently.
--}}
<x-admin.layout title="Create Feature Flag">

    <div style="margin-bottom:20px">
        <a href="{{ route('admin.feature-flags.index') }}" style="color:#888;font-size:13px;text-decoration:none">&larr; Back to Feature Flags</a>
    </div>

    @if($errors->any())
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#fca5a5;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            <strong>Could not create:</strong>
            <ul style="margin-top:6px;padding-left:20px">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.feature-flags.store') }}" style="max-width:760px;margin:0 auto">
        @csrf

        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:20px">
            <h2 style="font-size:16px;font-weight:600;margin-bottom:6px">Identity</h2>
            <p style="font-size:12px;color:#777;margin-bottom:20px">
                These fields can be edited later. The new flag is created in the OFF state &mdash; configure strategy and rollout on the next screen.
            </p>

            <div class="form-group">
                <label for="key">Key (machine identifier) <span style="color:#ef4444">*</span></label>
                <input type="text" id="key" name="key" class="form-input"
                       value="{{ old('key') }}" required maxlength="80"
                       pattern="[a-z0-9_\.]+"
                       placeholder="new_player_ui">
                <div style="font-size:11px;color:#666;margin-top:4px">
                    Lowercase letters, digits, dots, and underscores only. This is what your code calls &mdash;
                    <code style="background:#0f0f0f;padding:2px 6px;border-radius:4px;color:#C5A55A">feature('new_player_ui')</code>.
                    Choose carefully; renaming requires updating every call site.
                </div>
                @error('key')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <label for="name">Display Name <span style="color:#ef4444">*</span></label>
                <input type="text" id="name" name="name" class="form-input"
                       value="{{ old('name') }}" required maxlength="160"
                       placeholder="New Video Player UI (Beta)">
                <div style="font-size:11px;color:#666;margin-top:4px">Human-friendly label shown in this admin and in dashboards.</div>
                @error('name')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="form-input" rows="3" maxlength="1000"
                          placeholder="What does this flag gate? When can it be removed?">{{ old('description') }}</textarea>
                <div style="font-size:11px;color:#666;margin-top:4px">Optional but recommended &mdash; future-you will want context when reviewing flags six months from now.</div>
                @error('description')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
            </div>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end">
            <a href="{{ route('admin.feature-flags.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-gold">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Create Flag
            </button>
        </div>
    </form>

</x-admin.layout>
