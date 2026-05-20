<x-admin.layout title="New Blog Post">
    @include('admin.blog.posts._form', ['formAction' => route('admin.blog.posts.store'), 'method' => 'POST'])
</x-admin.layout>
