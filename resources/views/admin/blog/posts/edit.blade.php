<x-admin.layout title="Edit Post">
    @include('admin.blog.posts._form', ['formAction' => route('admin.blog.posts.update', $post), 'method' => 'PUT'])
</x-admin.layout>
