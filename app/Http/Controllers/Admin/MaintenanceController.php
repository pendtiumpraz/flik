<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MaintenanceState;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * MaintenanceController
 * --------------------------------------------------------------------------
 * Drives the /admin/maintenance dashboard:
 *
 *   GET   /admin/maintenance        index — current state + history
 *   POST  /admin/maintenance/enable enable — flip the kill switch on
 *   POST  /admin/maintenance/disable disable — flip the kill switch off
 *   POST  /admin/maintenance/update update — edit config WITHOUT toggling
 *
 * Every state-changing action writes a `maintenance.*` row to audit_logs so
 * the index view can render a chronological "who flipped what" timeline.
 *
 * Authorization is per-route via `can:system.maintenance` (added to the
 * seeder), which only super_admin holds out of the box.
 */
class MaintenanceController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $state = MaintenanceState::current()->loadMissing('enabledBy:id,name,email');

        // History — the audit-log rows we write below.
        $history = AuditLog::query()
            ->where('action', 'like', 'maintenance.%')
            ->with('user:id,name,email')
            ->latest('created_at')
            ->latest('id')
            ->limit(30)
            ->get();

        // Available roles for the bypass checkboxes. We surface ONLY the
        // staff-flavoured roles so the picker doesn't get polluted with
        // 'user' or freshly-invented custom roles that aren't staff.
        $availableRoles = [
            'super_admin'      => 'Super Admin',
            'admin'            => 'Admin',
            'content_manager'  => 'Content Manager',
            'content_editor'   => 'Content Editor',
            'finance'          => 'Finance',
            'moderator'        => 'Moderator',
            'customer_support' => 'Customer Support',
        ];

        return view('admin.maintenance.index', [
            'title'          => 'Maintenance Mode',
            'state'          => $state,
            'history'        => $history,
            'availableRoles' => $availableRoles,
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $state = MaintenanceState::current();
        $state->enable(
            actor: $request->user(),
            message: $data['message'],
            allowIps: $data['allow_ips'],
            allowRoles: $data['allow_roles'],
            scheduledUntil: $data['scheduled_until'],
        );

        $this->audit->log('maintenance.enabled', $state, [
            'message'         => $data['message'],
            'allow_ips'       => $data['allow_ips'],
            'allow_roles'     => $data['allow_roles'],
            'scheduled_until' => $data['scheduled_until']?->toIso8601String(),
        ]);

        return redirect()
            ->route('admin.maintenance.index')
            ->with('success', 'Maintenance mode enabled. Only allow-listed users can access the site.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $state = MaintenanceState::current();
        $wasEnabled = $state->isEnabled();
        $state->disable();

        if ($wasEnabled) {
            $this->audit->log('maintenance.disabled', $state, []);
        }

        return redirect()
            ->route('admin.maintenance.index')
            ->with('success', 'Maintenance mode disabled. Site is live for everyone again.');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $state = MaintenanceState::current();
        $state->updateConfig(
            message: $data['message'],
            allowIps: $data['allow_ips'],
            allowRoles: $data['allow_roles'],
            scheduledUntil: $data['scheduled_until'],
        );

        $this->audit->log('maintenance.updated', $state, [
            'message'         => $data['message'],
            'allow_ips'       => $data['allow_ips'],
            'allow_roles'     => $data['allow_roles'],
            'scheduled_until' => $data['scheduled_until']?->toIso8601String(),
        ]);

        return redirect()
            ->route('admin.maintenance.index')
            ->with('success', 'Maintenance configuration saved. Toggle is unchanged.');
    }

    /**
     * Validate and normalise the request payload used by both `enable`
     * and `update`. Splits the IP textarea into one IP per line and runs
     * each through `validateIp` so a malformed entry is rejected loudly
     * instead of silently disabling bypass for that operator.
     *
     * @return array{message: ?string, allow_ips: array<int,string>, allow_roles: array<int,string>, scheduled_until: ?Carbon}
     */
    private function validated(Request $request): array
    {
        // Parse the IP textarea into an array first so we can run per-line
        // validation. `array` rule then validates the shape; `ip` per-item.
        $rawIps = (string) $request->input('allow_ips', '');
        $ips = array_values(array_filter(array_map(
            'trim',
            preg_split("/\r\n|\n|\r/", $rawIps) ?: []
        )));
        $request->merge(['allow_ips' => $ips]);

        $data = $request->validate([
            'message'           => ['nullable', 'string', 'max:1000'],
            'allow_ips'         => ['array', 'max:50'],
            'allow_ips.*'       => ['string', 'ip'],
            'allow_roles'       => ['array', 'max:20'],
            'allow_roles.*'     => ['string', 'max:50'],
            'scheduled_until'   => ['nullable', 'date', 'after:now'],
        ], [
            'allow_ips.*.ip' => 'One of the listed IPs is not a valid IPv4/IPv6 address.',
            'scheduled_until.after' => 'Scheduled-until must be in the future.',
        ]);

        return [
            'message'         => $data['message'] ?? null,
            'allow_ips'       => $data['allow_ips'] ?? [],
            'allow_roles'     => $data['allow_roles'] ?? ['super_admin'],
            'scheduled_until' => isset($data['scheduled_until'])
                ? Carbon::parse($data['scheduled_until'])
                : null,
        ];
    }
}
