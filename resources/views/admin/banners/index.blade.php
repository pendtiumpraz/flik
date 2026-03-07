<x-admin.layout title="Banner Management">

    <div style="display:flex;gap:24px;flex-wrap:wrap">
        <!-- Add Banner Form -->
        <div style="width:340px;flex-shrink:0">
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px">
                <h3 style="font-size:15px;font-weight:600;margin-bottom:16px">Add Banner</h3>
                <form method="POST" action="{{ route('admin.banners.store') }}">
                    @csrf
                    <div class="form-group">
                        <label>Title *</label>
                        <input type="text" name="title" class="form-input" placeholder="Banner title..." required value="{{ old('title') }}">
                        @error('title')<div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="form-input" rows="2" placeholder="Optional description...">{{ old('description') }}</textarea>
                    </div>
                    <div class="form-group">
                        <label>Image URL *</label>
                        <input type="text" name="image_url" class="form-input" placeholder="https://..." required value="{{ old('image_url') }}">
                        @error('image_url')<div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group">
                        <label>Link URL</label>
                        <input type="text" name="link_url" class="form-input" placeholder="https://..." value="{{ old('link_url') }}">
                    </div>
                    <div class="form-group">
                        <label>Position *</label>
                        <select name="position" class="form-input">
                            <option value="hero">Hero (top banner)</option>
                            <option value="sidebar">Sidebar</option>
                            <option value="popup">Popup</option>
                            <option value="footer">Footer</option>
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="starts_at" class="form-input" value="{{ old('starts_at') }}">
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="ends_at" class="form-input" value="{{ old('ends_at') }}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Sort Order</label>
                        <input type="number" name="sort_order" class="form-input" value="0" min="0">
                    </div>
                    <div class="form-group" style="display:flex;align-items:center;gap:8px">
                        <label class="toggle"><input type="checkbox" name="is_active" value="1" checked><span class="slider"></span></label>
                        <span style="font-size:13px;color:#aaa">Active</span>
                    </div>
                    <button type="submit" class="btn btn-gold" style="width:100%;justify-content:center">
                        + Add Banner
                    </button>
                </form>
            </div>
        </div>

        <!-- Banner List -->
        <div style="flex:1;min-width:300px">
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
                <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
                    <h3 style="font-size:15px;font-weight:600">All Banners ({{ $banners->count() }})</h3>
                </div>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:80px">Preview</th>
                            <th>Title</th>
                            <th>Position</th>
                            <th>Status</th>
                            <th>Schedule</th>
                            <th style="width:120px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($banners as $banner)
                        <tr>
                            <td>
                                <img src="{{ $banner->image_url }}" style="width:60px;height:34px;border-radius:4px;object-fit:cover;background:#333" onerror="this.style.background='#333';this.src=''">
                            </td>
                            <td>
                                <div style="font-weight:500;color:#fff">{{ $banner->title }}</div>
                                @if($banner->description)<div style="font-size:11px;color:#555;margin-top:2px">{{ Str::limit($banner->description, 50) }}</div>@endif
                            </td>
                            <td><span class="badge badge-blue">{{ $banner->position }}</span></td>
                            <td>
                                @if($banner->is_active)
                                    <span class="badge badge-green">Active</span>
                                @else
                                    <span class="badge" style="background:rgba(100,100,100,0.2);color:#666">Inactive</span>
                                @endif
                            </td>
                            <td style="font-size:11px;color:#666">
                                @if($banner->starts_at || $banner->ends_at)
                                    {{ $banner->starts_at?->format('d/m/Y') ?? '∞' }} — {{ $banner->ends_at?->format('d/m/Y') ?? '∞' }}
                                @else
                                    Always
                                @endif
                            </td>
                            <td>
                                <div style="display:flex;gap:4px">
                                    <form method="POST" action="{{ route('admin.banners.toggle', $banner) }}">
                                        @csrf @method('PUT')
                                        <button type="submit" class="btn btn-ghost btn-sm">{{ $banner->is_active ? '⏸' : '▶' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.banners.destroy', $banner) }}" onsubmit="return confirm('Delete banner?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" style="text-align:center;color:#555;padding:32px">No banners yet</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</x-admin.layout>
