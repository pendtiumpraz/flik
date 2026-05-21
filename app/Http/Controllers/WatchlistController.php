<?php

namespace App\Http\Controllers;

use App\Models\Watchlist;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class WatchlistController extends Controller
{
    public function index()
    {
        $movies = auth()->user()->watchlistMovies()
            ->with('genres')
            ->latest('watchlists.created_at')
            ->get();

        return view('watchlist.index', compact('movies'));
    }

    public function toggle(Request $request)
    {
        $request->validate(['movie_id' => 'required|exists:movies,id']);

        $userId = auth()->id();
        $movieId = $request->movie_id;

        $existing = Watchlist::where('user_id', $userId)
            ->where('movie_id', $movieId)
            ->first();

        if ($existing) {
            $existing->delete();
            $added = false;
        } else {
            try {
                Watchlist::create([
                    'user_id' => $userId,
                    'movie_id' => $movieId,
                ]);
            } catch (QueryException $e) {
                // Unique-key race: two tabs raced past the where()->first()
                // check above. The DB unique index on (user_id, movie_id)
                // is the source of truth — treat as already-added rather
                // than 500ing. Toggle remains conceptually idempotent.
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062 // MySQL duplicate-key
                    && ! str_contains(strtolower($e->getMessage()), 'unique')
                ) {
                    throw $e;
                }
            }
            $added = true;
        }

        if ($request->wantsJson()) {
            return response()->json([
                'added' => $added,
                'message' => $added ? 'Ditambahkan ke watchlist' : 'Dihapus dari watchlist',
            ]);
        }

        return back()->with('success', $added ? 'Ditambahkan ke watchlist!' : 'Dihapus dari watchlist!');
    }
}
