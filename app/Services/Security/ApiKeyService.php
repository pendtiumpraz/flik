<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\ApiKey;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * API key issuance + verification.
 *
 * Plaintext shape: "flk_" + 32 hex chars from random_bytes(16) → 36 char total.
 * Storage: sha256(plaintext). The plaintext is returned exactly ONCE from
 * {@see generate()} and is unrecoverable thereafter — that's the design,
 * matching Sanctum / GitHub PAT / Stripe API key UX.
 *
 * Verification is constant-time per row via PHP's hash_equals(); the row is
 * located by direct index lookup on key_hash so there's no timing oracle for
 * "valid hash but expired" vs "no such hash".
 */
final class ApiKeyService
{
    /**
     * Generate a new API key.
     *
     * @param  string                                $name      Human label shown in the admin list.
     * @param  array<int,string>                     $abilities Scope list. ["*"] = full access.
     * @param  \DateTimeInterface|null               $expiresAt Optional hard expiry.
     * @return array{plaintext: string, model: \App\Models\ApiKey}
     */
    public function generate(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): array
    {
        // 16 bytes = 128 bits of entropy → 32 hex chars. Plenty for an API key
        // and short enough to copy comfortably.
        $plaintext = ApiKey::KEY_PREFIX.bin2hex(random_bytes(16));

        $model = ApiKey::create([
            'name'               => $name,
            'key_hash'           => hash('sha256', $plaintext),
            'key_prefix'         => substr($plaintext, 0, ApiKey::PREFIX_LENGTH),
            'abilities'          => $abilities !== [] ? array_values($abilities) : ['*'],
            'expires_at'         => $expiresAt ? Carbon::instance($expiresAt) : null,
            'created_by_user_id' => Auth::id(),
        ]);

        return [
            'plaintext' => $plaintext,
            'model'     => $model,
        ];
    }

    /**
     * Verify a plaintext key. Returns the row on success (and stamps last-used
     * metadata) or null on any failure.
     *
     * Failure modes intentionally collapsed to a single null return so the
     * middleware always responds with 401 + a generic message — no oracle for
     * "valid format but revoked" vs "doesn't exist".
     */
    public function verify(string $plaintext): ?ApiKey
    {
        $plaintext = trim($plaintext);

        if ($plaintext === '' || ! str_starts_with($plaintext, ApiKey::KEY_PREFIX)) {
            return null;
        }

        $hash = hash('sha256', $plaintext);

        /** @var ApiKey|null $key */
        $key = ApiKey::query()->where('key_hash', $hash)->first();

        if ($key === null) {
            return null;
        }

        // Defence in depth — verify the stored hash matches via constant-time
        // compare even though the lookup was already exact. Cheap, makes the
        // intent explicit, and survives any future weakening of the unique
        // index (collation changes, etc.).
        if (! hash_equals($key->key_hash, $hash)) {
            return null;
        }

        if (! $key->isActive()) {
            return null;
        }

        // request() resolves the bound HTTP request when present, or a fresh
        // one synthesised from globals (e.g. when called from a queue worker
        // that never had a request context). Either way, recordUse() captures
        // whatever IP the caller is exposing.
        $key->recordUse(request());

        return $key;
    }

    /**
     * Soft-revoke a key. Returns true if the row existed and was newly
     * revoked, false if no such row or already revoked.
     */
    public function revoke(int $id): bool
    {
        /** @var ApiKey|null $key */
        $key = ApiKey::query()->find($id);

        if ($key === null || $key->isRevoked()) {
            return false;
        }

        $key->forceFill(['revoked_at' => Carbon::now()])->save();

        return true;
    }
}
