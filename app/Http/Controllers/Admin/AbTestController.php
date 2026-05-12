<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbExperiment;
use App\Services\Analytics\AbTestFramework;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin CRUD + lifecycle for the A/B testing framework (D6).
 *
 * Routes (registered in routes/web.php under the admin group):
 *   GET    /admin/ab-tests                        → index
 *   GET    /admin/ab-tests/create                 → create
 *   POST   /admin/ab-tests                        → store
 *   GET    /admin/ab-tests/{experiment}           → show (report)
 *   POST   /admin/ab-tests/{experiment}/{action}  → run lifecycle action
 *                                                   (start | pause | resume |
 *                                                    conclude)
 *
 * The framework itself ({@see AbTestFramework}) handles the per-user
 * sticky assignment + conversion tracking. This controller only manages
 * the experiment record and renders reports.
 */
class AbTestController extends Controller
{
    /** Lifecycle actions accepted by `act()`. */
    public const ACTIONS = ['start', 'pause', 'resume', 'conclude'];

    public function __construct(
        protected AbTestFramework $framework,
    ) {}

    /** List all experiments, newest first. */
    public function index(): View
    {
        $experiments = AbExperiment::query()
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.ab.index', [
            'experiments' => $experiments,
            'statuses'    => AbExperiment::STATUSES,
        ]);
    }

    /** Form for creating a new experiment. */
    public function create(): View
    {
        return view('admin.ab.create');
    }

    /**
     * Persist a new experiment.
     *
     * Variants are submitted as parallel arrays (`variant_keys[]` and
     * `variant_weights[]`) and zipped into the JSON column.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'             => ['required', 'string', 'max:200'],
            'slug'             => ['nullable', 'string', 'max:200', Rule::unique('ab_experiments', 'slug')],
            'hypothesis'       => ['nullable', 'string', 'max:5000'],
            'variant_keys'     => ['required', 'array', 'min:2'],
            'variant_keys.*'   => ['required', 'string', 'max:50'],
            'variant_weights'  => ['required', 'array', 'min:2'],
            'variant_weights.*' => ['required', 'numeric', 'min:0'],
            'start_now'        => ['nullable', 'boolean'],
        ]);

        $variants = [];
        foreach ($validated['variant_keys'] as $i => $key) {
            $weight = (float) ($validated['variant_weights'][$i] ?? 1);
            if ($weight <= 0 || $key === '') {
                continue;
            }
            $variants[] = ['key' => $key, 'weight' => $weight];
        }

        if (count($variants) < 2) {
            return back()
                ->withInput()
                ->withErrors(['variant_keys' => 'Need at least 2 variants with weight > 0.']);
        }

        $slug = $validated['slug']
            ?: Str::slug($validated['name']) . '-' . Str::lower(Str::random(4));

        $startNow = (bool) ($validated['start_now'] ?? false);

        $experiment = AbExperiment::create([
            'slug'       => $slug,
            'name'       => $validated['name'],
            'hypothesis' => $validated['hypothesis'] ?? null,
            'variants'   => $variants,
            'status'     => $startNow
                ? AbExperiment::STATUS_RUNNING
                : AbExperiment::STATUS_DRAFT,
            'started_at' => $startNow ? Carbon::now() : null,
        ]);

        return redirect()
            ->route('admin.ab-tests.show', $experiment)
            ->with('success', 'Experiment "' . $experiment->name . '" created.');
    }

    /**
     * Show a single experiment + its variant report.
     */
    public function show(AbExperiment $experiment): View
    {
        $report = $this->framework->report($experiment->slug);

        // Best-performing variant by conversion_rate (assigned >= 1).
        $leader = null;
        foreach ($report['variants'] as $v) {
            if ($v['assigned'] === 0) {
                continue;
            }
            if ($leader === null || $v['conversion_rate'] > $leader['conversion_rate']) {
                $leader = $v;
            }
        }

        return view('admin.ab.show', [
            'experiment' => $experiment,
            'report'     => $report,
            'leader'     => $leader,
            'actions'    => self::ACTIONS,
        ]);
    }

    /**
     * Lifecycle transitions: start | pause | resume | conclude.
     *
     * Concluding requires a `winner_variant` in the POST body (may be empty
     * to record "no winner"). All other actions only flip the status.
     */
    public function act(Request $request, AbExperiment $experiment, string $action): RedirectResponse
    {
        if (!in_array($action, self::ACTIONS, true)) {
            abort(404);
        }

        switch ($action) {
            case 'start':
                if ($experiment->status !== AbExperiment::STATUS_DRAFT) {
                    return back()->with('error', 'Only draft experiments can be started.');
                }
                $experiment->forceFill([
                    'status'     => AbExperiment::STATUS_RUNNING,
                    'started_at' => $experiment->started_at ?? Carbon::now(),
                ])->save();
                $msg = 'Experiment started.';
                break;

            case 'pause':
                if ($experiment->status !== AbExperiment::STATUS_RUNNING) {
                    return back()->with('error', 'Only running experiments can be paused.');
                }
                $experiment->forceFill(['status' => AbExperiment::STATUS_PAUSED])->save();
                $msg = 'Experiment paused — no new assignments will be minted.';
                break;

            case 'resume':
                if ($experiment->status !== AbExperiment::STATUS_PAUSED) {
                    return back()->with('error', 'Only paused experiments can be resumed.');
                }
                $experiment->forceFill(['status' => AbExperiment::STATUS_RUNNING])->save();
                $msg = 'Experiment resumed.';
                break;

            case 'conclude':
                $validated = $request->validate([
                    'winner_variant' => ['nullable', 'string', 'max:50'],
                ]);
                $experiment->forceFill([
                    'status'         => AbExperiment::STATUS_COMPLETED,
                    'ended_at'       => Carbon::now(),
                    'winner_variant' => $validated['winner_variant'] ?: null,
                ])->save();
                $msg = 'Experiment concluded.';
                break;

            default:
                $msg = 'No-op.';
        }

        return redirect()
            ->route('admin.ab-tests.show', $experiment)
            ->with('success', $msg);
    }
}
