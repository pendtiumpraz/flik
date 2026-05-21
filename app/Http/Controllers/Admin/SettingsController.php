<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Audit\AuditLogger;
use Database\Seeders\SettingSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Runtime-editable settings registry — read/write via the admin UI.
 *
 * Grouped tabs are driven by the `group` column on each row, so adding a
 * new tab is just inserting a setting with a new `group` value (no view
 * code change needed).
 *
 * Mutating action is a single bulk POST — the index form posts EVERY
 * setting's value back at once, and we diff against the DB row to
 * decide which need a real UPDATE (so we don't log no-op changes).
 */
class SettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        // Defensive authorization — fall back to is_admin if gate missing.
        $user = auth()->user();
        if ($user && !$user->is_admin) {
            try {
                $this->authorize('system.settings');
            } catch (\Throwable $e) {
                abort(403, 'Tidak punya izin system.settings');
            }
        }

        // Defensive table check — render empty state instead of 500 when
        // settings migration hasn't been applied yet.
        $settings = collect();
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                $settings = Setting::query()
                    ->orderBy('group')
                    ->orderBy('key')
                    ->get()
                    ->groupBy('group');
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SettingsController: query failed', ['error' => $e->getMessage()]);
        }

        return view('admin.settings.index', [
            'grouped' => $settings,
            'groupKeys' => $settings->keys()->all(),
        ]);
    }

    /**
     * Bulk update — accepts a `settings[<id>]` payload with the new
     * scalar/text value for each row.
     *
     * For booleans the missing-from-form case is interpreted as `false`
     * (unchecked checkbox doesn't submit at all). Per-setting validation
     * rules from the `validation_rules` column are honoured — if a rule
     * fails, the whole save aborts with field-level errors so the user
     * sees exactly which input is wrong.
     */
    public function update(Request $request): RedirectResponse
    {
        $this->authorize('system.settings');

        $submitted = (array) $request->input('settings', []);

        $settings = Setting::query()
            ->whereIn('id', array_keys($submitted))
            ->get()
            ->keyBy('id');

        // Build a per-field validation array. We map the validator's
        // attribute path back to `settings.<id>` so error messages
        // surface next to the right input in the view.
        $rules = [];
        $attributeLabels = [];
        foreach ($submitted as $id => $value) {
            $setting = $settings->get((int) $id);
            if ($setting === null) {
                continue;
            }

            $ruleString = (string) ($setting->validation_rules ?? '');
            if ($ruleString !== '') {
                $rules["settings.$id"] = $ruleString;
            }
            $attributeLabels["settings.$id"] = $setting->key;
        }

        if ($rules !== []) {
            $request->validate($rules, [], $attributeLabels);
        }

        // Diff + persist inside a transaction so a mid-save failure
        // leaves the DB consistent. Per-row audit entries are emitted
        // AFTER commit so we don't write audit rows for a rollback.
        $changes = [];

        DB::transaction(function () use ($submitted, $settings, &$changes): void {
            foreach ($submitted as $id => $rawValue) {
                $setting = $settings->get((int) $id);
                if ($setting === null) {
                    continue;
                }

                // Bool inputs that came in as "1" / "0" / null get
                // normalised through the mutator (Setting::castIn).
                if ($setting->type === 'bool') {
                    $rawValue = (bool) $rawValue;
                }

                $previousStored = $setting->getAttributes()['value'] ?? null;
                $setting->value = $rawValue; // routed through mutator
                $newStored = $setting->getAttributes()['value'] ?? null;

                if ($previousStored === $newStored) {
                    continue; // no-op — skip save + audit
                }

                $setting->save();

                $changes[] = [
                    'key' => $setting->key,
                    'before' => $previousStored,
                    'after' => $newStored,
                    'is_secret' => (bool) $setting->is_secret,
                ];
            }
        });

        // Audit each real change. Secrets get their values redacted in
        // the log payload so we don't pile up plaintext keys in audit_logs.
        foreach ($changes as $change) {
            $this->audit->security(
                event: 'admin.setting.updated',
                meta: [
                    'key' => $change['key'],
                    'before' => $change['is_secret'] ? '***' : $change['before'],
                    'after' => $change['is_secret'] ? '***' : $change['after'],
                ],
            );
        }

        $count = count($changes);
        $msg = $count === 0
            ? 'No changes — settings already up to date.'
            : "Saved {$count} setting" . ($count === 1 ? '' : 's') . '.';

        return redirect()
            ->route('admin.settings.index')
            ->with('success', $msg);
    }

    /**
     * "Restore defaults" action — idempotently re-applies the canonical
     * seed values from {@see SettingSeeder::restoreDefaults()}. Destructive:
     * every seeded setting reverts to its baseline, including the value.
     * Custom settings (not in the seeder) are untouched.
     */
    public function seed(): RedirectResponse
    {
        $this->authorize('system.settings');

        $count = SettingSeeder::restoreDefaults();

        $this->audit->security(
            event: 'admin.setting.restore_defaults',
            meta: ['restored_count' => $count],
        );

        return redirect()
            ->route('admin.settings.index')
            ->with('success', "Restored {$count} setting" . ($count === 1 ? '' : 's') . ' to canonical defaults.');
    }
}
