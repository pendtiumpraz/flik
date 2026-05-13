<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\Audit\AuditLogger;
use App\Services\Security\ApiKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Admin CRUD for API keys.
 *
 * Plaintext keys are displayed exactly once after creation, via a flash
 * payload that survives a single redirect. The view pops the flash and
 * shows it in a modal — refreshing the page hides the key permanently.
 */
class ApiKeyController extends Controller
{
    public function __construct(
        private readonly ApiKeyService $service,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * List all API keys, newest first.
     */
    public function index(): View
    {
        $keys = ApiKey::query()
            ->with('creator:id,name,email')
            ->orderByDesc('id')
            ->paginate(25);

        return view('admin.api-keys.index', [
            'keys'         => $keys,
            'newPlaintext' => session('new_api_key_plaintext'),
            'newName'      => session('new_api_key_name'),
        ]);
    }

    /**
     * Issue a new API key.
     *
     * Validation:
     *   - name: required, distinct, ≤ 80 chars (matches the column).
     *   - abilities: optional CSV ("publish,read") trimmed + deduped. Empty
     *                or "*" → wildcard.
     *   - expires_at: optional date — must be in the future when present.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:80'],
            'abilities'  => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $abilities = $this->parseAbilities((string) ($data['abilities'] ?? ''));
        $expiresAt = isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null;

        $result = $this->service->generate(
            name: $data['name'],
            abilities: $abilities,
            expiresAt: $expiresAt,
        );

        $this->audit->log('api_key.created', $result['model'], [
            'name'       => $result['model']->name,
            'prefix'     => $result['model']->key_prefix,
            'abilities'  => $result['model']->abilities,
            'expires_at' => $result['model']->expires_at?->toIso8601String(),
        ]);

        return redirect()
            ->route('admin.api-keys.index')
            ->with('new_api_key_plaintext', $result['plaintext'])
            ->with('new_api_key_name', $result['model']->name)
            ->with('success', 'API key created. Copy the plaintext now — it will not be shown again.');
    }

    /**
     * Soft-revoke a key. We never hard-delete because audit_logs entries
     * may reference the row.
     */
    public function destroy(int $apiKey): RedirectResponse
    {
        $key = ApiKey::query()->find($apiKey);

        if ($key === null) {
            return redirect()
                ->route('admin.api-keys.index')
                ->with('success', 'API key not found.');
        }

        $revoked = $this->service->revoke($key->id);

        if ($revoked) {
            $this->audit->log('api_key.revoked', $key, [
                'prefix' => $key->key_prefix,
                'name'   => $key->name,
            ]);
        }

        return redirect()
            ->route('admin.api-keys.index')
            ->with('success', $revoked
                ? "API key '{$key->name}' revoked."
                : "API key '{$key->name}' was already revoked.");
    }

    /**
     * Parse the CSV abilities input into a clean, deduped string list.
     *
     * @return array<int,string>
     */
    private function parseAbilities(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '*') {
            return ['*'];
        }

        $parts = array_filter(array_map(
            static fn (string $s): string => trim($s),
            explode(',', $raw)
        ), static fn (string $s): bool => $s !== '');

        // If wildcard sneaks in alongside specifics, keep only wildcard.
        if (in_array('*', $parts, true)) {
            return ['*'];
        }

        return array_values(array_unique($parts));
    }
}
