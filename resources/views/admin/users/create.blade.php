<x-admin.layout title="Add User">
    <div style="margin-bottom:20px">
        <a href="{{ route('admin.users.index') }}" class="btn btn-ghost btn-sm">← Back to Users</a>
    </div>
    <h2 style="font-size:22px;font-weight:600;margin-bottom:24px;text-align:center">Add New User</h2>
    @include('admin.users._form')
</x-admin.layout>
