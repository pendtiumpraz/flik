<?php

declare(strict_types=1);

namespace App\Support;

/**
 * SecurityEvents
 * --------------------------------------------------------------------------
 * Canonical taxonomy of security-flavoured audit events. Every call to
 * {@see \App\Services\Audit\AuditLogger::security()} MUST pass one of these
 * constants as the action name so:
 *
 *   - the `/admin/audit-logs` view can group / filter / colour-code rows,
 *   - {@see \App\Services\Security\SecurityAlertService} can map events to
 *     severity buckets without hard-coding free-form strings,
 *   - the daily security digest can deduplicate identical events by name,
 *   - downstream documentation (`docs/security/event-taxonomy.md`) stays
 *     in sync because every event has exactly one place to live.
 *
 * The values follow a `<domain>.<noun>.<verb>` shape (max 80 chars to fit
 * the `audit_logs.action` column). They are stable IDs — once shipped they
 * MUST NOT be renamed. To deprecate an event, leave the constant in place
 * and add a comment marking it deprecated.
 *
 * @see docs/security/event-taxonomy.md
 */
final class SecurityEvents
{
    // ── Authentication ────────────────────────────────────────────────
    public const LOGIN_SUCCESS = 'auth.login.success';

    public const LOGIN_FAILED = 'auth.login.failed';

    public const LOGIN_LOCKED_OUT = 'auth.login.locked_out';

    public const LOGOUT = 'auth.logout';

    public const SESSION_REVOKED = 'auth.session.revoked';

    // ── Two-factor (TOTP / recovery codes) ────────────────────────────
    public const TWO_FACTOR_ENABLED = 'auth.2fa.enabled';

    public const TWO_FACTOR_DISABLED = 'auth.2fa.disabled';

    public const TWO_FACTOR_VERIFIED = 'auth.2fa.verified';

    public const TWO_FACTOR_FAILED = 'auth.2fa.failed';

    // ── Password / email lifecycle ────────────────────────────────────
    public const PASSWORD_CHANGED = 'auth.password.changed';

    public const PASSWORD_RESET_REQUESTED = 'auth.password.reset_requested';

    public const PASSWORD_RESET_COMPLETED = 'auth.password.reset_completed';

    public const EMAIL_VERIFIED = 'auth.email.verified';

    // ── Behavioural / heuristic security signals ──────────────────────
    public const NEW_DEVICE_LOGIN = 'security.new_device';

    public const NEW_COUNTRY_LOGIN = 'security.new_country';

    public const SUSPICIOUS_GEO_VELOCITY = 'security.suspicious.geo_velocity';

    public const PRIVILEGE_ESCALATION_ATTEMPT = 'security.priv_escalation_attempt';

    public const RATE_LIMIT_HIT = 'security.rate_limit_hit';

    public const HONEYPOT_HIT = 'security.honeypot_hit';

    public const CSP_VIOLATION = 'security.csp_violation';

    public const SSRF_BLOCKED = 'security.ssrf_blocked';

    public const FILE_UPLOAD_REJECTED = 'security.file_upload_rejected';

    public const WAF_BLOCKED = 'security.waf.blocked';

    public const WAF_IP_BANNED = 'security.waf.ip_banned';

    // ── Admin actions ─────────────────────────────────────────────────
    public const ADMIN_ACTION = 'admin.action';

    public const ADMIN_USER_UNLOCK = 'admin.user.unlock';

    public const ADMIN_USER_DELETED = 'admin.user.deleted';

    // ── Privacy (GDPR / data subject rights) ──────────────────────────
    public const DATA_EXPORT_REQUESTED = 'privacy.export_requested';

    public const DATA_EXPORT_DOWNLOADED = 'privacy.export_downloaded';

    public const ACCOUNT_DELETED = 'privacy.account_deleted';

    // ── Payments ──────────────────────────────────────────────────────
    public const PAYMENT_CHARGEBACK = 'payment.chargeback';

    // ── DRM / playback ────────────────────────────────────────────────
    public const DRM_KEY_REQUEST = 'drm.key_request';

    public const DRM_KEY_DENIED = 'drm.key_denied';

    /**
     * Severity bucket for an event. Used by the audit-logs view to colour
     * rows and by {@see \App\Services\Security\SecurityAlertService} as a
     * fallback when an event isn't in its own explicit table.
     *
     * Ranking: critical > high > medium > low.
     *
     * @return 'low'|'medium'|'high'|'critical'
     */
    public static function severity(string $event): string
    {
        return match ($event) {
            self::PRIVILEGE_ESCALATION_ATTEMPT,
            self::SUSPICIOUS_GEO_VELOCITY,
            self::ACCOUNT_DELETED,
            self::PAYMENT_CHARGEBACK,
            self::ADMIN_USER_DELETED => 'critical',

            self::LOGIN_LOCKED_OUT,
            self::SSRF_BLOCKED,
            self::FILE_UPLOAD_REJECTED,
            self::WAF_IP_BANNED,
            self::TWO_FACTOR_DISABLED,
            self::TWO_FACTOR_FAILED,
            self::DRM_KEY_DENIED,
            self::HONEYPOT_HIT,
            self::SESSION_REVOKED => 'high',

            self::NEW_DEVICE_LOGIN,
            self::NEW_COUNTRY_LOGIN,
            self::RATE_LIMIT_HIT,
            self::CSP_VIOLATION,
            self::WAF_BLOCKED,
            self::PASSWORD_CHANGED,
            self::PASSWORD_RESET_COMPLETED,
            self::PASSWORD_RESET_REQUESTED,
            self::TWO_FACTOR_ENABLED,
            self::ADMIN_USER_UNLOCK,
            self::ADMIN_ACTION,
            self::DATA_EXPORT_REQUESTED,
            self::DATA_EXPORT_DOWNLOADED,
            self::LOGIN_FAILED => 'medium',

            // LOGIN_SUCCESS, LOGOUT, EMAIL_VERIFIED, TWO_FACTOR_VERIFIED,
            // DRM_KEY_REQUEST and any unknown / future event default to "low"
            // — informational, aggregated in the daily digest.
            default => 'low',
        };
    }

    /**
     * Whole list of canonical event values. Used by the audit-logs view
     * to populate the "Security only" filter chip dropdown.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::LOGIN_SUCCESS,
            self::LOGIN_FAILED,
            self::LOGIN_LOCKED_OUT,
            self::LOGOUT,
            self::SESSION_REVOKED,
            self::TWO_FACTOR_ENABLED,
            self::TWO_FACTOR_DISABLED,
            self::TWO_FACTOR_VERIFIED,
            self::TWO_FACTOR_FAILED,
            self::PASSWORD_CHANGED,
            self::PASSWORD_RESET_REQUESTED,
            self::PASSWORD_RESET_COMPLETED,
            self::EMAIL_VERIFIED,
            self::NEW_DEVICE_LOGIN,
            self::NEW_COUNTRY_LOGIN,
            self::SUSPICIOUS_GEO_VELOCITY,
            self::PRIVILEGE_ESCALATION_ATTEMPT,
            self::RATE_LIMIT_HIT,
            self::HONEYPOT_HIT,
            self::CSP_VIOLATION,
            self::SSRF_BLOCKED,
            self::FILE_UPLOAD_REJECTED,
            self::WAF_BLOCKED,
            self::WAF_IP_BANNED,
            self::ADMIN_ACTION,
            self::ADMIN_USER_UNLOCK,
            self::ADMIN_USER_DELETED,
            self::DATA_EXPORT_REQUESTED,
            self::DATA_EXPORT_DOWNLOADED,
            self::ACCOUNT_DELETED,
            self::PAYMENT_CHARGEBACK,
            self::DRM_KEY_REQUEST,
            self::DRM_KEY_DENIED,
        ];
    }
}
