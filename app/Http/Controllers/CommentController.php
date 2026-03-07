<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'movie_id' => 'required|exists:movies,id',
            'body' => 'required|string|max:2000',
            'parent_id' => 'nullable|exists:comments,id',
            'is_spoiler' => 'boolean',
        ]);

        Comment::create([
            'user_id' => auth()->id(),
            'movie_id' => $request->movie_id,
            'parent_id' => $request->parent_id,
            'body' => $request->body,
            'is_spoiler' => $request->boolean('is_spoiler'),
        ]);

        return back()->with('success', 'Komentar berhasil ditambahkan!');
    }

    public function destroy(Comment $comment)
    {
        if ($comment->user_id !== auth()->id() && !auth()->user()->is_admin) {
            abort(403);
        }

        $comment->delete();
        return back()->with('success', 'Komentar dihapus!');
    }
}
