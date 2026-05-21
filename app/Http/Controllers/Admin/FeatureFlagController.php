<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Admin CRUD for feature flags + master-switch + strategy editor.
 *
 * Every action is gated on the `system.feature_flags` permission (added
 * to the seeder in this swarm). Mutating actions emit an audit_logs
 * entry via {@see AuditLogger::security()} so reviewers can trace who
 * flipped what and when — flags often gate revenue features so this
 * is treated as a security-tier audit event, not a generic one.
 */
class FeatureFlagController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * List every flag with status pills, strategy badge, and last-updated stamp.
     */
    public function index(): View
    {
        $this->authorize('system.feature_flags');

        $flags = FeatureFlag::query()
            ->orderBy('key')
            ->get();

        return view('admin.feature-flags.index', compact('flags'));
    }

    /**
     * Render the "new flag" form. The form posts to {@see store()} which
     * only takes the identity fields (key/name/description); strategy +
     * config are configured AFTER creation on the edit screen — keeps the
     * accidental-deploy footgun closed (a freshly-created flag is always
     * `off` until an operator deliberately opts it in).
     */
    public function create(): View
    {
        $this->authorize('system.feature_flags');

        return view('admin.feature-flags.create', [
            'strategies' => FeatureFlag::STRATEGIES,
        ]);
    }

    /**
     * Persist a brand-new flag. Defaults to off + 'off' strategy so an
     * accidentally-saved row never goes live silently.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('system.feature_flags');

        $validated = $request->validate([
            'key' => [
                'required', 'string', 'max:80',
                'regex:/^[a-z0-9_\.]+$/',
                'unique:feature_flags,key',
            ],
            'name' => 'required|string|max:160',
            'description' => 'nullable|string|max:1000',
        ], [
            'key.regex' => 'Key must contain only lowercase letters, digits, dots, and underscores.',
        ]);

        $flag = FeatureFlag::create([
            'key' => $validated['key'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_enabled' => false,
            'strategy' => 'off',
            'strategy_config' => null,
        ]);

        $this->audit->security(
            event: 'admin.feature_flag.created',
            subject: $flag,
            meta: ['key' => $flag->key, 'name' => $flag->name],
        );

        return redirect()
            ->route('admin.feature-flags.edit', $flag)
            ->with('success', "Feature flag \"{$flag->name}\" created. Configure its strategy below.");
    }

    /**
     * Edit form — full strategy picker + conditionally-shown config inputs.
     * Pre-populates role checkboxes from the {@see Role} catalog and the
     * percentage slider from the existing strategy_config.
     */
    public function edit(FeatureFlag $featureFlag): View
    {
        $this->authorize('system.feature_flags');

        // Roles for the 'role' strategy checkbox grid. Same defensive
        // pattern as the sidebar — if the Role model/table isn't around
        // yet, we degrade to an empty list rather than 500.
        $availableRoles = class_exists(Role::class)
            ? Role::query()->orderBy('priority')->orderBy('name')->get(['name', 'display_name'])
            : collect();

        $strategyConfig = $featureFlag->strategy_config ?? [];

        return view('admin.feature-flags.edit', [
            'flag' => $featureFlag,
            'strategies' => FeatureFlag::STRATEGIES,
            'availableRoles' => $availableRoles,
            'selectedRoles' => is_array($strategyConfig['roles'] ?? null) ? $strategyConfig['roles'] : [],
            'percentage' => (int) ($strategyConfig['percentage'] ?? 0),
            'userIds' => is_array($strategyConfig['user_ids'] ?? null)
                ? implode(', ', $strategyConfig['user_ids'])
                : '',
        ]);
    }

    /**
     * Update — top-level fields + strategy + strategy-specific config.
     *
     * The strategy_config payload is normalized per-strategy so the JSON
     * we persist always matches the documented shape (no leaking of the
     * percentage slider value when the admin picked 'role', etc.).
     */
    public function update(Request $request, FeatureFlag $featureFlag): RedirectResponse
    {
        $this->authorize('system.feature_flags');

        $validated = $request->validate([
            'name' => 'required|string|max:160',
            'description' => 'nullable|string|max:1000',
            'is_enabled' => 'sometimes|boolean',
            'strategy' => 'required|in:' . implode(',', FeatureFlag::STRATEGIES),
            'roles' => 'nullable|array',
            'roles.*' => 'string|max:60',
            'percentage' => 'nullable|integer|min:0|max:100',
            'user_ids' => 'nullable|string|max:1000', // comma-separated ids
        ]);

        $before = [
            'is_enabled' => $featureFlag->is_enabled,
            'strategy' => $featureFlag->strategy,
            'strategy_config' => $featureFlag->strategy_config,
        ];

        $isEnabled = (bool) $request->boolean('is_enabled');
        $strategy = (string) $validated['strategy'];

        // Strategy-specific config builder. Anything outside the picked
        // strategy is dropped on the floor — otherwise stale fields
        // would linger in the JSON blob and confuse the next editor.
        $strategyConfig = match ($strategy) {
            'role' => ['roles' => array_values($validated['roles'] ?? [])],
            'percentage' => ['percentage' => (int) ($validated['percentage'] ?? 0)],
            'users' => ['user_ids' => $this->parseUserIds($validated['user_ids'] ?? '')],
            default => null, // on / off / authed / guests carry no config
        };

        // Stamp rollout_started_at the first time we flip from off → on,
        // OR when the user re-enables after a disable. Keeps the "ramping
        // since" timestamp meaningful in admin.
        $rolloutStarted = $featureFlag->rollout_started_at;
        if ($isEnabled && (! $before['is_enabled'] || $rolloutStarted === null)) {
            $rolloutStarted = Carbon::now();
        }
        // Reset stamp when we go back to OFF, so the next ramp-up reads
        // as a brand-new rollout.
        if (! $isEnabled) {
            $rolloutStarted = null;
        }

        $featureFlag->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_enabled' => $isEnabled,
            'strategy' => $strategy,
            'strategy_config' => $strategyConfig,
            'rollout_started_at' => $rolloutStarted,
        ])->save();

        $this->audit->security(
            event: 'admin.feature_flag.updated',
            subject: $featureFlag->fresh(),
            meta: [
                'key' => $featureFlag->key,
                'before' => $before,
                'after' => [
                    'is_enabled' => $isEnabled,
                    'strategy' => $strategy,
                    'strategy_config' => $strategyConfig,
                ],
            ],
        );

        return redirect()
            ->route('admin.feature-flags.edit', $featureFlag)
            ->with('success', "Feature flag \"{$featureFlag->name}\" updated.");
    }

    /**
     * Delete a flag. Audit-logged. Hard delete — no soft-delete column
     * on this table because a removed flag should disappear from the
     * evaluator immediately (and re-creation with the same key is cheap).
     */
    public function destroy(FeatureFlag $featureFlag): RedirectResponse
    {
        $this->authorize('system.feature_flags');

        $snapshot = [
            'id' => $featureFlag->id,
            'key' => $featureFlag->key,
            'name' => $featureFlag->name,
            'is_enabled' => $featureFlag->is_enabled,
            'strategy' => $featureFlag->strategy,
        ];

        $featureFlag->delete();

        $this->audit->security(
            event: 'admin.feature_flag.deleted',
            meta: $snapshot,
        );

        return redirect()
            ->route('admin.feature-flags.index')
            ->with('success', "Feature flag \"{$snapshot['name']}\" deleted.");
    }

    /**
     * Parse the comma-separated user-ids textarea field. Filters out
     * non-numeric entries silently so a stray "[1, 2]" or "1, abc, 3"
     * still resolves the digits the operator meant.
     *
     * @return array<int, int>
     */
    private function parseUserIds(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $ids = [];
        foreach ($parts as $part) {
            if ($part === '' || ! ctype_digit($part)) {
                continue;
            }
            $ids[] = (int) $part;
        }

        return array_values(array_unique($ids));
    }
}
