{{-- Shared create/edit form for promo codes. --}}
@php
    $pc = $promoCode ?? null;
    $selectedPlans = $pc?->applies_to_plans ?? [];
    $selectedPlans = is_array($selectedPlans) ? array_map('intval', $selectedPlans) : [];
@endphp

<div style="max-width:760px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
        <h2 style="font-size:22px;font-weight:700;color:#fff">
            {{ $pc ? 'Edit Promo Code' : 'New Promo Code' }}
        </h2>
        <a href="{{ route('admin.promo-codes.index') }}" class="btn btn-ghost btn-sm">
            ← Back
        </a>
    </div>

    @if($errors->any())
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;padding:12px 16px;border-radius:8px;margin-bottom:16px">
            <strong>Periksa input:</strong>
            <ul style="margin:6px 0 0 18px;font-size:13px">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $action }}"
        style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px">
        @csrf
        @if($method !== 'POST')
            @method($method)
        @endif

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div class="form-group">
                <label>Code *</label>
                <input type="text" name="code" maxlength="40" required
                    pattern="[A-Za-z0-9_\-]+"
                    class="form-input"
                    style="text-transform:uppercase;font-family:'Outfit',monospace;letter-spacing:0.5px"
                    value="{{ old('code', $pc?->code) }}"
                    placeholder="WELCOME10">
                <small style="color:#666;font-size:11px">Otomatis disimpan UPPERCASE. Hanya A-Z, 0-9, dash & underscore.</small>
            </div>
            <div class="form-group">
                <label>Campaign Name *</label>
                <input type="text" name="name" maxlength="120" required class="form-input"
                    value="{{ old('name', $pc?->name) }}"
                    placeholder="Welcome 10% off">
            </div>
        </div>

        <div class="form-group">
            <label>Description</label>
            <textarea name="description" class="form-input">{{ old('description', $pc?->description) }}</textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div class="form-group">
                <label>Discount Type *</label>
                <select name="discount_type" class="form-input" required>
                    @foreach($types as $t)
                        <option value="{{ $t }}" @selected(old('discount_type', $pc?->discount_type) === $t)>
                            {{ ucwords(str_replace('_', ' ', $t)) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label>Discount Value *</label>
                <input type="number" step="0.01" min="0" name="discount_value" required class="form-input"
                    value="{{ old('discount_value', $pc?->discount_value) }}"
                    placeholder="10">
                <small style="color:#666;font-size:11px">Percentage: 0-100, Fixed: IDR, Free Trial Days: hari.</small>
            </div>
        </div>

        <div class="form-group">
            <label>Applies to Plans</label>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;padding:12px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:8px">
                @foreach($plans as $plan)
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
                        <input type="checkbox" name="applies_to_plans[]" value="{{ $plan->id }}"
                            @checked(in_array((int) $plan->id, $selectedPlans, true))>
                        <span>{{ $plan->name }}</span>
                    </label>
                @endforeach
            </div>
            <small style="color:#666;font-size:11px">Kosongkan semua = berlaku untuk semua paket.</small>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
            <div class="form-group">
                <label>Max Uses (total)</label>
                <input type="number" name="max_uses" min="1" class="form-input"
                    value="{{ old('max_uses', $pc?->max_uses) }}"
                    placeholder="∞">
                <small style="color:#666;font-size:11px">Kosongkan = unlimited.</small>
            </div>
            <div class="form-group">
                <label>Max Uses per User</label>
                <input type="number" name="max_uses_per_user" min="0" class="form-input"
                    value="{{ old('max_uses_per_user', $pc?->max_uses_per_user ?? 1) }}">
            </div>
            <div class="form-group">
                <label>Min Subscription Months</label>
                <input type="number" name="min_subscription_months" min="1" class="form-input"
                    value="{{ old('min_subscription_months', $pc?->min_subscription_months ?? 1) }}">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div class="form-group">
                <label>Starts at</label>
                <input type="datetime-local" name="starts_at" class="form-input"
                    value="{{ old('starts_at', $pc?->starts_at?->format('Y-m-d\TH:i')) }}">
            </div>
            <div class="form-group">
                <label>Expires at</label>
                <input type="datetime-local" name="expires_at" class="form-input"
                    value="{{ old('expires_at', $pc?->expires_at?->format('Y-m-d\TH:i')) }}">
            </div>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:18px;margin-top:8px;margin-bottom:20px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;color:#ccc">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                    @checked(old('is_active', $pc?->is_active ?? true))>
                <span>Active</span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;color:#ccc">
                <input type="hidden" name="is_first_time_only" value="0">
                <input type="checkbox" name="is_first_time_only" value="1"
                    @checked(old('is_first_time_only', $pc?->is_first_time_only ?? false))>
                <span>First-time customers only</span>
            </label>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;border-top:1px solid #2a2a2a;padding-top:20px">
            <a href="{{ route('admin.promo-codes.index') }}" class="btn btn-ghost btn-sm">Cancel</a>
            <button type="submit" class="btn btn-gold btn-sm">{{ $submit }}</button>
        </div>
    </form>
</div>
