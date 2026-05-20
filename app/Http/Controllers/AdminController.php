<?php

namespace App\Http\Controllers;

use App\Models\Cast;
use App\Models\Genre;
use App\Models\Movie;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Security\FileUploadValidator;
use App\Services\Security\LoginThrottle;
use App\Services\Security\VirusScanner;
use App\Support\SafeFilename;
use App\Support\SecurityEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminController extends Controller
{
    public function dashboard()
    {
        // PWA install count — soft-fail if the table hasn't been migrated yet
        // (admin dashboards must never 500 on a missing optional table).
        $pwaInstalls = 0;
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('pwa_installs')) {
                $pwaInstalls = (int) \App\Models\PwaInstall::count();
            }
        } catch (\Throwable $e) {
            $pwaInstalls = 0;
        }

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
            'pwa_installs' => $pwaInstalls,
            'payment_enabled' => ! empty(config('services.midtrans.server_key')),
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

    public function storeMovie(Request $request, FileUploadValidator $uploads, VirusScanner $scanner)
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
            // The deep validation lives in FileUploadValidator below; the
            // 'mimes' rule here is just a fast first-pass hint that maps
            // to a friendlier error message via the request validator.
            'video_file' => 'nullable|file|mimes:mp4,webm,mov,mkv|max:512000',
            'is_popular' => 'boolean',
            'is_trending' => 'boolean',
            'genres' => 'array',
        ]);

        $movieData = collect($validated)->except('genres', 'video_file')->toArray();
        $movieData['is_popular'] = $request->boolean('is_popular');
        $movieData['is_trending'] = $request->boolean('is_trending');

        // Handle video upload — magic-byte sniff + extension check + size cap
        // + filename safety + (optional) ClamAV scan, all centralised in
        // FileUploadValidator so the same rules are enforced for every
        // upload surface.
        if ($request->hasFile('video_file')) {
            $upload = $request->file('video_file');

            $check = $uploads->validateVideo($upload);
            if (! $check['ok']) {
                return back()->withErrors(['video_file' => $check['errors']])->withInput();
            }

            if (! $scanner->scan($check['safe_path'] ?? $upload->getRealPath())) {
                return back()->withErrors(['video_file' => 'File ditolak oleh anti-malware scanner.'])->withInput();
            }

            // Always rename to a UUID-based safe filename — never persist
            // the client-supplied name, which is hostile input.
            // Operator precedence note: `'movie_' . ($x ?? 'video')` —
            // the parens matter; without them PHP concatenates first and
            // the `??` never fires.
            $safeName = SafeFilename::generate(
                $upload->getClientOriginalName(),
                'movie_'.($movieData['title'] ?? 'video')
            );

            $path = $upload->storeAs('videos', $safeName, 'public');
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

    public function updateMovie(Request $request, Movie $movie, FileUploadValidator $uploads, VirusScanner $scanner)
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
            'video_file' => 'nullable|file|mimes:mp4,webm,mov,mkv|max:512000',
            'is_popular' => 'boolean',
            'is_trending' => 'boolean',
            'genres' => 'array',
        ]);

        $movieData = collect($validated)->except('genres', 'video_file')->toArray();
        $movieData['is_popular'] = $request->boolean('is_popular');
        $movieData['is_trending'] = $request->boolean('is_trending');

        // Handle video upload — see storeMovie() for the rationale; we
        // mirror the same defence-in-depth pipeline here.
        if ($request->hasFile('video_file')) {
            $upload = $request->file('video_file');

            $check = $uploads->validateVideo($upload);
            if (! $check['ok']) {
                return back()->withErrors(['video_file' => $check['errors']])->withInput();
            }

            if (! $scanner->scan($check['safe_path'] ?? $upload->getRealPath())) {
                return back()->withErrors(['video_file' => 'File ditolak oleh anti-malware scanner.'])->withInput();
            }

            // Delete old video if exists. Done AFTER validation so a bad
            // upload doesn't destroy the existing-good file.
            if ($movie->video_path) {
                Storage::disk($movie->video_disk ?? 'public')->delete($movie->video_path);
            }

            $safeName = SafeFilename::generate(
                $upload->getClientOriginalName(),
                'movie_'.($movie->slug ?: 'video')
            );

            $path = $upload->storeAs('videos', $safeName, 'public');
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
        $movie->castMembers()->detach();
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
        // Eager-load `roles` so the index view can render the per-user role
        // badges without an N+1 query storm. `method_exists` is the seam
        // for the RBAC rollout: when peer ROLE #1's Role model and pivot
        // relation are in place, this loads them; before that, the view
        // still renders (the relation simply isn't there).
        $query = User::query()->orderBy('created_at', 'desc');
        if (method_exists(User::class, 'roles')) {
            $query->with('roles:id,name,display_name');
        }
        $users = $query->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    // ── USER ↔ ROLE ASSIGNMENT ────────────────────────────────
    // These two methods power the per-user "Manage Roles" page. They
    // intentionally live on AdminController (rather than a dedicated
    // UserRoleController) to match the spec and keep all user-related
    // admin actions in one place. Role CRUD lives in
    // App\Http\Controllers\Admin\RoleController.

    /**
     * Render the role-assignment view for a specific user.
     *
     * Authorisation: `users.assign_roles` ability is owned by peer
     * ROLE #3 (gate registration). Super-admins bypass via AuthService
     * Provider's Gate::before short-circuit.
     */
    public function editRoles(User $user)
    {
        $this->authorize('users.assign_roles');

        $roles = \App\Models\Role::query()
            ->withCount('permissions')
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        $assignedRoleIds = $user->roles()->pluck('roles.id')->all();

        return view('admin.users.roles', compact('user', 'roles', 'assignedRoleIds'));
    }

    /**
     * Persist a role-assignment change for a specific user.
     *
     * `exists:roles,id` is enforced per-row to prevent a tampered form
     * from silently attaching non-existent role IDs (which would then
     * pollute the pivot with dangling rows once an FK check ran).
     */
    public function updateRoles(Request $request, User $user)
    {
        $this->authorize('users.assign_roles');

        $validated = $request->validate([
            'roles' => 'array',
            'roles.*' => 'integer|exists:roles,id',
        ]);

        $before = $user->roles()->pluck('roles.id')->all();
        $after = $validated['roles'] ?? [];

        $user->roles()->sync($after);

        // SecurityEvent: route through ::security() so the change shows
        // up in the audit-logs "Security only" filter alongside other
        // privilege-affecting actions.
        try {
            app(AuditLogger::class)->security(
                event: 'admin.user.roles_updated',
                subject: $user,
                meta: [
                    'roles' => $after,
                    'before' => $before,
                    'added' => array_values(array_diff($after, $before)),
                    'removed' => array_values(array_diff($before, $after)),
                ],
            );
        } catch (\Throwable $e) {
            \Log::warning('AdminController: audit write failed for user roles update', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.users.index')
            ->with('success', "Roles for \"{$user->name}\" updated.");
    }

    public function toggleAdmin(User $user)
    {
        // Prevent removing own admin
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'Tidak bisa mengubah status admin sendiri!');
        }

        $user->update(['is_admin' => ! $user->is_admin]);
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
        $email = $user->email;
        $deletedId = $user->id;

        $user->delete();

        // Snapshot the identifying fields BEFORE delete so the audit row
        // carries useful context after the model is gone. Routed through
        // ::security() because admin-initiated user deletion is a critical
        // action that must light up the security dashboard.
        try {
            app(AuditLogger::class)->security(
                event: SecurityEvents::ADMIN_USER_DELETED,
                meta: [
                    'deleted_user_id' => $deletedId,
                    'deleted_email' => $email,
                    'deleted_name' => $name,
                ],
            );
        } catch (\Throwable $e) {
            \Log::warning('AdminController: audit write failed for user delete', [
                'deleted_user_id' => $deletedId,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()->route('admin.users.index')
            ->with('success', "User \"{$name}\" berhasil dihapus!");
    }

    /**
     * Clear login-attempt failures for a user that has been locked out by
     * the brute-force protection layer (App\Services\Security\LoginThrottle).
     *
     * Writes an audit_logs row so the unlock action is itself reviewable
     * later. Successful login_attempt rows are preserved as history.
     */
    public function unlockLogin(User $user, LoginThrottle $throttle, AuditLogger $audit)
    {
        $throttle->unlock($user->email);

        // Routed through ::security() so the unlock shows up under the
        // "Security only" filter chip alongside the LOGIN_LOCKED_OUT rows
        // that prompted it. Constant value 'admin.user.unlock' replaces
        // the legacy 'user.login_unlocked' string going forward.
        $audit->security(
            event: SecurityEvents::ADMIN_USER_UNLOCK,
            subject: $user,
            meta: ['email' => $user->email],
        );

        return redirect()->route('admin.users.index')
            ->with('success', "Login lock untuk \"{$user->name}\" berhasil dibuka.");
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
        $banner->update(['is_active' => ! $banner->is_active]);
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

    // ── AI PROVIDERS ───────────────────────────────────────────

    public function aiSettings()
    {
        $providers = \App\Models\AiProvider::orderBy('priority')->orderByDesc('is_active')->get();
        $catalog = \App\Models\AiProvider::PROVIDERS;

        return view('admin.ai-settings', compact('providers', 'catalog'));
    }

    public function storeAiProvider(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'provider' => 'required|string|max:60',
            'model' => 'required|string|max:120',
            'api_key' => 'required|string|min:10|max:255',
            'base_url' => 'nullable|url|max:255',
            'priority' => 'nullable|integer|min:1|max:999',
            'is_active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['priority'] = $data['priority'] ?? 100;

        if (empty($data['base_url']) && isset(\App\Models\AiProvider::PROVIDERS[$data['provider']])) {
            $data['base_url'] = \App\Models\AiProvider::PROVIDERS[$data['provider']]['base_url'] ?: null;
        }

        if ($data['is_default']) {
            \App\Models\AiProvider::where('is_default', true)->update(['is_default' => false]);
        }

        \App\Models\AiProvider::create($data);

        return redirect()->route('admin.ai.index')
            ->with('success', "AI provider \"{$data['name']}\" berhasil ditambahkan.");
    }

    public function updateAiProvider(Request $request, \App\Models\AiProvider $aiProvider)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'provider' => 'required|string|max:60',
            'model' => 'required|string|max:120',
            'api_key' => 'nullable|string|min:10|max:255',
            'base_url' => 'nullable|url|max:255',
            'priority' => 'nullable|integer|min:1|max:999',
            'is_active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['priority'] = $data['priority'] ?? $aiProvider->priority;

        if (empty($data['api_key'])) {
            unset($data['api_key']);
        }

        if ($data['is_default']) {
            \App\Models\AiProvider::where('id', '!=', $aiProvider->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $aiProvider->update($data);

        return redirect()->route('admin.ai.index')
            ->with('success', "AI provider \"{$aiProvider->name}\" berhasil diupdate.");
    }

    public function destroyAiProvider(\App\Models\AiProvider $aiProvider)
    {
        $name = $aiProvider->name;
        $aiProvider->delete();

        return redirect()->route('admin.ai.index')
            ->with('success', "AI provider \"{$name}\" berhasil dihapus.");
    }

    public function toggleAiProvider(\App\Models\AiProvider $aiProvider)
    {
        $aiProvider->update(['is_active' => ! $aiProvider->is_active]);
        $status = $aiProvider->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return redirect()->route('admin.ai.index')
            ->with('success', "Provider \"{$aiProvider->name}\" {$status}.");
    }

    public function pitchDeck()
    {
        $assumptions = [
            'film_count' => 350,
            'avg_size_gb' => 2,
            'avg_hours_per_user' => 10,
            'avg_bitrate_mbps' => 5,
            'cdn_cache_hit_ratio' => 0.30,
        ];

        return view('admin.pitch-deck', compact('assumptions'));
    }

    public function pitchDeckMarkdown()
    {
        $path = base_path('PITCH_DECK.md');
        abort_unless(file_exists($path), 404);

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'inline; filename="PITCH_DECK.md"',
        ]);
    }
}
