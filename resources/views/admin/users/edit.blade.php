<x-admin.layout title="Edit {{ $user->name }}">
    <div style="margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <a href="{{ route('admin.users.index') }}" class="btn btn-ghost btn-sm">← Back to Users</a>
        <span style="font-size:12px;color:#666">ID #{{ $user->id }} · joined {{ optional($user->created_at)->diffForHumans() }}</span>
    </div>
    <h2 style="font-size:22px;font-weight:600;margin-bottom:24px;text-align:center">Edit User: {{ $user->name }}</h2>
    @include('admin.users._form', ['user' => $user])
</x-admin.layout>
