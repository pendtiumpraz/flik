<?php

namespace App\Services\Drm;

/**
 * Immutable value object describing a DRM-related audit event.
 *
 * Used by key-issuance, concurrent-stream, and revocation flows to
 * produce a uniformly-shaped record that downstream sinks
 * (audit_logs table, SIEM webhook, structured log) can consume.
 *
 * NOT an Eloquent model — keep it cheap and side-effect-free. Sinks
 * decide how to persist via {@see toArray()}.
 */
final class DrmAuditEvent
{
    /**
     * Common action constants — keep in sync with anything that
     * filters audit logs by action name.
     */
    public const ACTION_KEY_ISSUE     = 'drm.key.issue';
    public const ACTION_KEY_DENY      = 'drm.key.deny';
    public const ACTION_CONCURRENT_DENY = 'drm.concurrent.deny';
    public const ACTION_SESSION_REVOKE  = 'drm.session.revoke';
    public const ACTION_FINGERPRINT_MISMATCH = 'drm.fingerprint.mismatch';

    public function __construct(
        public readonly string $action,
        public readonly ?int $userId,
        public readonly ?int $movieId,
        public readonly ?string $sessionToken,
        public readonly ?string $ip,
        public readonly ?string $country,
        public readonly bool $success,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * Build an audit event for a successful (or failed) key-issuance attempt.
     */
    public static function fromKeyIssue(
        ?int $userId,
        ?int $movieId,
        ?string $sessionToken,
        ?string $ip,
        ?string $country,
        bool $success,
        ?string $reason = null,
    ): self {
        return new self(
            action:       $success ? self::ACTION_KEY_ISSUE : self::ACTION_KEY_DENY,
            userId:       $userId,
            movieId:      $movieId,
            sessionToken: $sessionToken,
            ip:           $ip,
            country:      $country,
            success:      $success,
            reason:       $reason,
        );
    }

    /**
     * Build an audit event for a concurrent-stream-limit denial.
     */
    public static function fromConcurrentDeny(
        ?int $userId,
        ?int $movieId,
        ?string $sessionToken,
        ?string $ip,
        ?string $country,
        ?string $reason = 'Concurrent stream limit reached',
    ): self {
        return new self(
            action:       self::ACTION_CONCURRENT_DENY,
            userId:       $userId,
            movieId:      $movieId,
            sessionToken: $sessionToken,
            ip:           $ip,
            country:      $country,
            success:      false,
            reason:       $reason,
        );
    }

    /**
     * Flat array suitable for persisting to `audit_logs` or shipping
     * to a structured-log sink.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action'        => $this->action,
            'user_id'       => $this->userId,
            'movie_id'      => $this->movieId,
            'session_token' => $this->sessionToken,
            'ip'            => $this->ip,
            'country'       => $this->country,
            'success'       => $this->success,
            'reason'        => $this->reason,
        ];
    }
}
