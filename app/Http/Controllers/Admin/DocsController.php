<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * /admin/docs — Client-facing high-level architecture overview.
 *
 * Read-only page meant to help non-technical stakeholders understand how
 * FLiK fits together: what the user sees, what runs in the background,
 * how content gets delivered safely, where money flows, etc. Uses
 * Mermaid.js diagrams for flow visuals and large colourful cards for
 * scannable summaries.
 */
class DocsController extends Controller
{
    public function index(): View
    {
        // Pull live numbers so the doc reflects what we ACTUALLY have,
        // not a theoretical capability list.
        $stats = [
            'movies'        => $this->safeCount(\App\Models\Movie::class),
            'genres'        => $this->safeCount(\App\Models\Genre::class),
            'casts'         => $this->safeCount(\App\Models\Cast::class),
            'users'         => $this->safeCount(\App\Models\User::class),
            'subscriptions' => $this->safeCount(\App\Models\Subscription::class),
            'ai_calls'      => $this->safeCount(\App\Models\AiUsageLog::class),
            'comments'      => $this->safeCount(\App\Models\Comment::class),
            'audit_events'  => $this->safeCount(\App\Models\AuditLog::class),
        ];

        return view('admin.docs.index', compact('stats'));
    }

    private function safeCount(string $model): int
    {
        try {
            return (int) $model::count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
