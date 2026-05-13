<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Public legal pages: Privacy Policy, Terms of Service, Refund Policy.
 *
 * All endpoints are intentionally guest-accessible — they MUST be reachable
 * without an account so prospective users (and crawlers / app-store reviewers)
 * can read the disclosures before signing up. Linked from the footer + the
 * cookie consent banner.
 *
 * Pages are static Blade so we can ship copy edits via deploy without a
 * round-trip to the DB. Each view receives `$updatedAt` so the "Last
 * updated" date stays in one place — bump the constant when the policy
 * text materially changes.
 */
class LegalController extends Controller
{
    /**
     * ISO date of the last material change to the policy copy. Bump this
     * (and the `flik_cookie_consent` schema version in
     * `resources/js/cookie-consent.js`) whenever the disclosed processing
     * categories change so users get re-prompted.
     */
    private const LAST_UPDATED = '2026-05-13';

    public function privacy(): View
    {
        return view('legal.privacy', [
            'updatedAt' => self::LAST_UPDATED,
        ]);
    }

    public function terms(): View
    {
        return view('legal.terms', [
            'updatedAt' => self::LAST_UPDATED,
        ]);
    }

    public function refund(): View
    {
        return view('legal.refund', [
            'updatedAt' => self::LAST_UPDATED,
        ]);
    }
}
