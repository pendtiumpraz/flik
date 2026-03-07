<?php

namespace App\Http\Controllers;

use App\Models\Rating;
use Illuminate\Http\Request;

class RatingController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'movie_id' => 'required|exists:movies,id',
            'score' => 'required|integer|min:1|max:10',
            'review' => 'nullable|string|max:1000',
        ]);

        $rating = Rating::updateOrCreate(
            [
                'user_id' => auth()->id(),
                'movie_id' => $request->movie_id,
            ],
            [
                'score' => $request->score,
                'review' => $request->review,
            ]
        );

        if ($request->wantsJson()) {
            return response()->json([
                'rating' => $rating,
                'message' => 'Rating berhasil disimpan!',
            ]);
        }

        return back()->with('success', 'Rating berhasil disimpan!');
    }

    public function destroy(Request $request)
    {
        Rating::where('user_id', auth()->id())
            ->where('movie_id', $request->movie_id)
            ->delete();

        return back()->with('success', 'Rating dihapus!');
    }
}
