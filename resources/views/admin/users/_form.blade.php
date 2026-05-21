@php
    $isEdit = isset($user) && $user->exists;
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.users.update', $user) : route('admin.users.store') }}"
      style="max-width:680px;margin:0 auto">
    @csrf
    @if($isEdit) @method('PUT') @endif

    @if($errors->any())
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            @foreach($errors->all() as $error)
                <div>• {{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;display:grid;gap:18px">
        <div>
            <label style="display:block;color:#aaa;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px">Name</label>
            <input name="name" type="text" required value="{{ old('name', $user->name ?? '') }}"
                   class="form-input" style="width:100%;padding:10px 14px">
        </div>

        <div>
            <label style="display:block;color:#aaa;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px">Username <span style="color:#666;text-transform:none">(opsional)</span></label>
            <input name="username" type="text" value="{{ old('username', $user->username ?? '') }}"
                   class="form-input" style="width:100%;padding:10px 14px"
                   placeholder="huruf/angka/garis bawah, min 3 karakter">
        </div>

        <div>
            <label style="display:block;color:#aaa;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px">Email</label>
            <input name="email" type="email" required value="{{ old('email', $user->email ?? '') }}"
                   class="form-input" style="width:100%;padding:10px 14px">
        </div>

        <div>
            <label style="display:block;color:#aaa;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px">
                Password {{ $isEdit ? '(kosongkan kalau tidak diubah)' : '' }}
            </label>
            <input name="password" type="password" {{ $isEdit ? '' : 'required' }} minlength="8"
                   class="form-input" style="width:100%;padding:10px 14px">
        </div>

        <div>
            <label style="display:block;color:#aaa;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px">Konfirmasi password</label>
            <input name="password_confirmation" type="password" {{ $isEdit ? '' : 'required' }} minlength="8"
                   class="form-input" style="width:100%;padding:10px 14px">
        </div>

        <div style="display:flex;gap:24px;flex-wrap:wrap;padding-top:8px;border-top:1px solid #2a2a2a">
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;color:#ddd;font-size:14px">
                <input type="hidden" name="is_admin" value="0">
                <input type="checkbox" name="is_admin" value="1" style="accent-color:#C5A55A;width:16px;height:16px"
                       {{ old('is_admin', $user->is_admin ?? false) ? 'checked' : '' }}>
                Admin
            </label>
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;color:#ddd;font-size:14px">
                <input type="hidden" name="email_verified" value="0">
                <input type="checkbox" name="email_verified" value="1" style="accent-color:#C5A55A;width:16px;height:16px"
                       {{ old('email_verified', ($user->email_verified_at ?? null) !== null) ? 'checked' : '' }}>
                Email sudah ter-verifikasi
            </label>
        </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end">
        <a href="{{ route('admin.users.index') }}" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-gold">{{ $isEdit ? 'Update User' : 'Create User' }}</button>
    </div>
</form>
