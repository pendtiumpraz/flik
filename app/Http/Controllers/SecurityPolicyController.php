<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Public-facing security policy + vulnerability report intake.
 *
 * Both endpoints are intentionally mounted OUTSIDE the auth middleware group:
 * researchers (and the curl-friendly RFC 9116 audience) must be able to reach
 * the policy and submit a report without holding a session.
 *
 * The submission endpoint logs to the `security` channel and sends an email
 * to security@flik.example.com. Tight throttling lives at the route definition.
 */
class SecurityPolicyController extends Controller
{
    /**
     * Render the markdown-rendered disclosure policy.
     */
    public function policy(): View
    {
        return view('security.policy');
    }

    /**
     * Render the vulnerability report submission form.
     */
    public function reportForm(): View
    {
        return view('security.report');
    }

    /**
     * Receive a vulnerability report and forward it to the security team.
     *
     * We never persist this to a public model — security reports are emailed
     * + logged to the `security` channel only. That keeps disclosures off any
     * admin dashboard until a human triages them.
     */
    public function reportSubmit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reporter_name' => 'nullable|string|max:120',
            'reporter_email' => 'required|email|max:190',
            'severity' => 'required|in:low,medium,high,critical',
            'title' => 'required|string|max:200',
            'description' => 'required|string|max:10000',
        ]);

        // Write to the security log channel — fans out to whatever sink ops
        // configured (Slack, Loki, file). Never log the description body at
        // info level: keep it warning so it surfaces in alerting.
        Log::channel('security')->warning('Vulnerability report received', [
            'reporter_email' => $data['reporter_email'],
            'severity' => $data['severity'],
            'title' => $data['title'],
            'ip' => $request->ip(),
            'ua' => $request->userAgent(),
        ]);

        // Email the security team. We use raw text — HTML rendering of an
        // attacker-controlled body in a mail client is a phishing vector.
        try {
            Mail::raw(
                "Severity: {$data['severity']}\n"
                ."From: {$data['reporter_email']}".(! empty($data['reporter_name']) ? " ({$data['reporter_name']})" : '')."\n"
                ."IP: {$request->ip()}\n"
                ."UA: {$request->userAgent()}\n"
                ."\n"
                ."Title: {$data['title']}\n"
                ."\n"
                ."---\n"
                .$data['description'],
                function ($message) use ($data): void {
                    $message->to('security@flik.example.com')
                        ->subject('[FLiK Security] '.$data['severity'].': '.$data['title'])
                        ->replyTo($data['reporter_email']);
                }
            );
        } catch (\Throwable $e) {
            // Mail failures must NOT swallow the report. Log loud and still
            // tell the user we got it — the channel log above is the source
            // of truth.
            Log::channel('security')->error('Vulnerability report mail failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('security.report.form')
            ->with('success', 'Terima kasih. Laporan keamananmu sudah kami terima dan akan ditindaklanjuti dalam 48 jam.');
    }
}
