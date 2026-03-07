<?php

namespace App\Http\Controllers;

use App\Models\Cast;
use App\Models\Genre;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function dashboard()
    {
        $stats = [
            'total_movies' => Movie::count(),
            'total_genres' => Genre::count(),
            'total_casts' => Cast::count(),
            'total_users' => User::count(),
            'popular_movies' => Movie::popular()->count(),
            'trending_movies' => Movie::trending()->count(),
            'total_ratings' => \App\Models\Rating::count(),
            'total_comments' => \App\Models\Comment::count(),
            'total_watchlists' => \App\Models\Watchlist::count(),
            'active_banners' => \App\Models\Banner::where('is_active', true)->count(),
            'payment_enabled' => !empty(config('services.midtrans.server_key')),
            'storage_disk' => config('filesystems.default'),
        ];

        $recentMovies = Movie::with('genres')
            ->latest()
            ->take(5)
            ->get();

        $recentUsers = User::latest()->take(5)->get();

        return view('admin.dashboard', compact('stats', 'recentMovies', 'recentUsers'));
    }

    // ── MOVIES CRUD ────────────────────────────────────────────

    public function movies(Request $request)
    {
        $query = Movie::with('genres');

        if ($search = $request->get('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        $movies = $query->orderBy('title')->paginate(15);

        return view('admin.movies.index', compact('movies'));
    }

    public function createMovie()
    {
        $genres = Genre::orderBy('name')->get();
        return view('admin.movies.form', [
            'movie' => null,
            'genres' => $genres,
        ]);
    }

    public function storeMovie(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'original_title' => 'nullable|string|max:255',
            'overview' => 'required|string',
            'poster_path' => 'nullable|string|max:500',
            'backdrop_path' => 'nullable|string|max:500',
            'release_date' => 'nullable|date',
            'vote_average' => 'nullable|numeric|min:0|max:10',
            'popularity' => 'nullable|numeric|min:0',
            'youtube_key' => 'nullable|string|max:50',
            'video_file' => 'nullable|file|mimes:mp4,webm,mov,avi|max:512000',
            'is_popular' => 'boolean',
            'is_trending' => 'boolean',
            'genres' => 'array',
        ]);

        $movieData = collect($validated)->except('genres', 'video_file')->toArray();
        $movieData['is_popular'] = $request->boolean('is_popular');
        $movieData['is_trending'] = $request->boolean('is_trending');

        // Handle video upload
        if ($request->hasFile('video_file')) {
            $path = $request->file('video_file')->store('videos', 'public');
            $movieData['video_path'] = $path;
            $movieData['video_disk'] = 'public';
        }

        $movie = Movie::create($movieData);

        if ($request->has('genres')) {
            $movie->genres()->sync($request->input('genres'));
        }

        return redirect()->route('admin.movies.index')
            ->with('success', "Film \"{$movie->title}\" berhasil ditambahkan!");
    }

    public function editMovie(Movie $movie)
    {
        $genres = Genre::orderBy('name')->get();
        $movie->load('genres');

        return view('admin.movies.form', [
            'movie' => $movie,
            'genres' => $genres,
        ]);
    }

    public function updateMovie(Request $request, Movie $movie)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'original_title' => 'nullable|string|max:255',
            'overview' => 'required|string',
            'poster_path' => 'nullable|string|max:500',
            'backdrop_path' => 'nullable|string|max:500',
            'release_date' => 'nullable|date',
            'vote_average' => 'nullable|numeric|min:0|max:10',
            'popularity' => 'nullable|numeric|min:0',
            'youtube_key' => 'nullable|string|max:50',
            'video_file' => 'nullable|file|mimes:mp4,webm,mov,avi|max:512000',
            'is_popular' => 'boolean',
            'is_trending' => 'boolean',
            'genres' => 'array',
        ]);

        $movieData = collect($validated)->except('genres', 'video_file')->toArray();
        $movieData['is_popular'] = $request->boolean('is_popular');
        $movieData['is_trending'] = $request->boolean('is_trending');

        // Handle video upload
        if ($request->hasFile('video_file')) {
            // Delete old video if exists
            if ($movie->video_path) {
                \Storage::disk($movie->video_disk ?? 'public')->delete($movie->video_path);
            }
            $path = $request->file('video_file')->store('videos', 'public');
            $movieData['video_path'] = $path;
            $movieData['video_disk'] = 'public';
        }

        $movie->update($movieData);

        if ($request->has('genres')) {
            $movie->genres()->sync($request->input('genres'));
        }

        return redirect()->route('admin.movies.index')
            ->with('success', "Film \"{$movie->title}\" berhasil diupdate!");
    }

    public function destroyMovie(Movie $movie)
    {
        $title = $movie->title;
        $movie->genres()->detach();
        $movie->casts()->detach();
        $movie->delete();

        return redirect()->route('admin.movies.index')
            ->with('success', "Film \"{$title}\" berhasil dihapus!");
    }

    // ── GENRES CRUD ────────────────────────────────────────────

    public function genres()
    {
        $genres = Genre::withCount('movies')->orderBy('name')->get();
        return view('admin.genres.index', compact('genres'));
    }

    public function storeGenre(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100|unique:genres,name',
        ]);

        Genre::create([
            'name' => $request->name,
            'slug' => \Str::slug($request->name),
        ]);

        return redirect()->route('admin.genres.index')
            ->with('success', "Genre \"{$request->name}\" berhasil ditambahkan!");
    }

    public function destroyGenre(Genre $genre)
    {
        $name = $genre->name;
        $genre->movies()->detach();
        $genre->delete();

        return redirect()->route('admin.genres.index')
            ->with('success', "Genre \"{$name}\" berhasil dihapus!");
    }

    // ── CASTS CRUD ────────────────────────────────────────────

    public function casts(Request $request)
    {
        $query = Cast::withCount('movies');

        if ($search = $request->get('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $casts = $query->orderBy('name')->paginate(20);
        return view('admin.casts.index', compact('casts'));
    }

    public function storeCast(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'profile_path' => 'nullable|string|max:500',
        ]);

        Cast::create([
            'name' => $request->name,
            'profile_path' => $request->profile_path,
        ]);

        return redirect()->route('admin.casts.index')
            ->with('success', "Cast \"{$request->name}\" berhasil ditambahkan!");
    }

    public function destroyCast(Cast $cast)
    {
        $name = $cast->name;
        $cast->movies()->detach();
        $cast->delete();

        return redirect()->route('admin.casts.index')
            ->with('success', "Cast \"{$name}\" berhasil dihapus!");
    }

    // ── USER MANAGEMENT ───────────────────────────────────────

    public function users()
    {
        $users = User::orderBy('created_at', 'desc')->paginate(20);
        return view('admin.users.index', compact('users'));
    }

    public function toggleAdmin(User $user)
    {
        // Prevent removing own admin
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'Tidak bisa mengubah status admin sendiri!');
        }

        $user->update(['is_admin' => !$user->is_admin]);
        $status = $user->is_admin ? 'admin' : 'user biasa';

        return redirect()->route('admin.users.index')
            ->with('success', "\"{$user->name}\" sekarang {$status}.");
    }

    public function destroyUser(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'Tidak bisa menghapus akun sendiri!');
        }

        $name = $user->name;
        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', "User \"{$name}\" berhasil dihapus!");
    }

    // ── BANNER MANAGEMENT ─────────────────────────────────────

    public function banners()
    {
        $banners = \App\Models\Banner::orderBy('sort_order')->orderByDesc('created_at')->get();
        return view('admin.banners.index', compact('banners'));
    }

    public function storeBanner(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'image_url' => 'required|string|max:500',
            'link_url' => 'nullable|string|max:500',
            'position' => 'required|in:hero,sidebar,popup,footer',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        \App\Models\Banner::create([
            'title' => $request->title,
            'description' => $request->description,
            'image_url' => $request->image_url,
            'link_url' => $request->link_url,
            'position' => $request->position,
            'is_active' => $request->boolean('is_active', true),
            'starts_at' => $request->starts_at,
            'ends_at' => $request->ends_at,
            'sort_order' => $request->sort_order ?? 0,
        ]);

        return redirect()->route('admin.banners.index')
            ->with('success', "Banner \"{$request->title}\" berhasil ditambahkan!");
    }

    public function toggleBanner(\App\Models\Banner $banner)
    {
        $banner->update(['is_active' => !$banner->is_active]);
        $status = $banner->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return redirect()->route('admin.banners.index')
            ->with('success', "Banner \"{$banner->title}\" {$status}.");
    }

    public function destroyBanner(\App\Models\Banner $banner)
    {
        $title = $banner->title;
        $banner->delete();

        return redirect()->route('admin.banners.index')
            ->with('success', "Banner \"{$title}\" berhasil dihapus!");
    }
}
